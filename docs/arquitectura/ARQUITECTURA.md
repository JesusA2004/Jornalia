# Arquitectura — MR LANA Asistencias

## Vista general

```
┌──────────────┐   HTTPS + Sanctum (Bearer)   ┌──────────────────────────────┐
│ App móvil    │ ───────────────────────────▶ │ Laravel 13 (Web/)            │
│ Expo / RN    │   /api/v1  (contrato TS)     │  ├─ API REST v1              │
│ cola offline │ ◀─────────────────────────── │  ├─ Web Inertia + Vue 3      │
└──────────────┘                              │  ├─ Dominio (app/Domain)     │
┌──────────────┐   Sesión + CSRF              │  ├─ Colas / Scheduler        │
│ Navegador    │ ───────────────────────────▶ │  └─ Notificaciones           │
└──────────────┘                              └──────────────┬───────────────┘
                                                             │ InnoDB
                                              ┌──────────────▼───────────────┐
                                              │ MariaDB 11.4                 │
                                              │  triggers append-only        │
                                              └──────────────────────────────┘
                                              Anclas de integridad → disco `anchors`
                                              (configurable a almacenamiento WORM)
```

Un solo backend (Laravel) sirve la web y la API. Toda regla laboral vive en `Web/app/Domain`.
La app móvil y la web sólo presentan información y capturan eventos.

## Contextos de dominio (`Web/app/Domain`)

| Contexto | Responsabilidad | Piezas clave |
|---|---|---|
| `Attendance` | Registro inalterable, firma de dispositivo, línea de tiempo reconocida, proyección diaria, rectificaciones, integridad | `PunchRecorder`, `HashChain`, `DeviceSignature`, `EffectiveTimeline`, `WorkdayCalculator`, `RectificationService`, `IntegrityVerifier`, `IntegrityAnchorService` |
| `Legal` | Normativa versionada y cálculos legales puros | `LegalFramework`, `LegalParameters`, `ShiftClassifier`, `OvertimeCalculator`, `HolidayGenerator` |
| `Compliance` | Alertas de cumplimiento y horas extraordinarias semanales | `ComplianceEvaluator` |
| `Scheduling` | Turno programado por fecha (rotativos, partidos, nocturnos, excepciones, festivos) | `ScheduleResolver`, `ScheduledShift` |
| `Vacations` | Saldos con trazabilidad, solicitudes, validación de prestaciones | `VacationService` |
| `Incidences` | Incidencias y evidencias | `IncidenceService` |
| `Approvals` | Autorizaciones multinivel configurables, sin autoaprobación | `ApprovalWorkflow` |
| `Security` | Permisos, alcance de datos (anti-IDOR), dispositivos | `PermissionCatalog`, `AccessScope`, `DeviceService` |
| `Organization` | Alta de organización con configuración legítima | `OrganizationProvisioner` |
| `Audit` | Bitácora append-only con redacción de datos sensibles | `AuditLogger` |
| `Biometrics` | Interfaz de proveedor facial (deshabilitada sin proveedor real) | `FaceVerificationProvider` |

## Multiempresa

- `CurrentOrganization` (scoped por petición/job) se fija en el middleware `organization`.
- Todo modelo con `organization_id` usa `BelongsToOrganization` (scope global + asignación al crear).
- Spatie Permission con *teams* = `organization_id`: roles y asignaciones por organización.
- `AccessScope` restringe además por sucursal asignada y por reportes directos del supervisor.
- Pruebas: `tests/Feature/Domain/TenancyIsolationTest.php` y pruebas de acceso cruzado en API/web.

## Modelo de datos (resumen)

- **Estructura**: `organizations`, `legal_entities` (razones sociales), `work_centers`, `branches`, `departments`, `positions`, `organization_user`, `branch_user`, `organization_settings`.
- **Personal**: `employees` (identificadores oficiales cifrados), `employments` (altas/bajas/reingresos), `employment_assignments` (sucursal, puesto, horario, supervisor y política **con vigencia**; los cambios nunca reescriben el pasado).
- **Horarios**: `schedules`, `schedule_segments` (día de ciclo, segmentos para horarios partidos, cruce de medianoche), `schedule_exceptions`.
- **Asistencia**: `devices`, `punch_challenges`, `attendance_punches` (append-only), `rectification_requests`, `attendance_adjustments` (append-only), `integrity_anchors` (append-only), `workday_summaries` (proyección recalculable).
- **Legal y operación**: `legal_versions` (con checksum), `vacation_entitlement_rules`, `holidays`, `incidence_types`, `incidences`, `vacation_policies`, `vacation_requests`, `vacation_adjustments`, `overtime_records`, `compliance_alerts`, `approval_flows`, `approval_decisions`, `attachments`.
- **Cumplimiento documental**: `policy_documents`, `document_acknowledgements` (append-only).
- **Auditoría y otros**: `audit_logs` (append-only), `report_exports`, `face_enrollments`, `face_verifications`, `integration_runs`.

## Flujo de un marcaje

1. App: `POST /attendance/challenge` → nonce de un solo uso (120 s).
2. App: autenticación del SO (huella/rostro/PIN) desbloquea el secreto del dispositivo guardado en el almacén seguro con `requireAuthentication`.
3. App: firma `HMAC(secret, client_uuid|type|device_time|nonce|device_uuid)` y envía `POST /attendance/punches`.
4. `PunchRecorder`: idempotencia por `client_uuid` → dispositivo autorizado → firma → nonce → política (método, ubicación, geocerca, dispositivo corporativo, verificación facial) → secuencia → anomalías.
5. Persistencia en transacción: bloqueo de la organización, `sequence`/`previous_hash`, sello HMAC.
6. Evento `PunchRecorded` → job `RecomputeWorkday` (idempotente, único) → `WorkdayCalculator` → `ComplianceEvaluator` (horas extra semanales y alertas).

Sin conexión: la app captura y firma (sin nonce), guarda en cola persistente y sincroniza con `POST /sync/punches`.
El servidor sella su hora de recepción; la hora del dispositivo queda como declarada y, si hay desfase o
sincronización tardía, el marcaje queda `flagged` para revisión. Nunca se descarta.

## Decisiones técnicas

| Decisión | Motivo |
|---|---|
| MariaDB con `DB_ENGINE=InnoDB` forzado | El servidor local tenía MyISAM por defecto (límite de 1000 bytes por índice, sin transacciones ni FK): causa del error de migración original. |
| Cadena con HMAC (no sha256 plano) | Con sha256 plano, quien tenga acceso a la BD podría recalcular la cadena. La clave vive fuera de la BD. Las anclas externas firmadas detectan reescrituras totales. |
| Triggers + trait `Immutable` | Defensa en profundidad: dominio y BD rechazan UPDATE/DELETE. Se documenta que un DBA con privilegios totales puede desactivarlos; por eso la detección criptográfica. |
| Descanso no registrado no se descuenta | LFT art. 64: si el trabajador no puede salir, el descanso es tiempo efectivo. |
| Excedente ≠ hora extra autorizada | El excedente se conserva íntegro en `overtime_records`; la autorización es un acto separado. |
| Versiones legales con checksum | Reproducibilidad histórica y detección de alteraciones de parámetros. |
| Hora oficial = recepción del servidor | La hora del dispositivo sólo se usa para capturas offline, marcada como no certificada. |
