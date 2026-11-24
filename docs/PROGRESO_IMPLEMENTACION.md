# Progreso de implementación — estado real

Actualizado: 2026-11-24 (fase 2 de la Revisión RH, rama `cierre/revision-rh`). Sólo se marca «probado» lo que se ejecutó de verdad. Requerimiento por requerimiento: [Trazabilidad](cierre/TRAZABILIDAD_REVISION_RH.md). Pendientes y aprobaciones: [Cierre técnico](cierre/CIERRE_TECNICO.md).

## Verificación final (fase 2)

<!-- RESULTADOS -->
<!-- El arquitecto registra aquí, con fecha, commit y base de datos usada:
     | Verificación | Resultado |
     Pest (suite completa, MariaDB real con triggers) · PHPStan · Pint · vue-tsc · vp check · npm run build ·
     Playwright (1440 y 390 px, claro y oscuro) · Jest · tsc / expo lint / expo-doctor · pytest (services/face) ·
     compilación Android (APK) e instalación en teléfono real. No escribir números estimados. -->

### Verificación anterior (histórica, 2026-11-17, antes de la Revisión RH)

Corresponde al commit `f26d36f`; no refleja los cambios de la fase 1.

| Verificación | Resultado |
|---|---|
| Backend Pest (BD limpia, MariaDB real con triggers) | 312 pasan, 0 fallan, 2 omitidas |
| Microservicio facial (pytest) | 26 pasan |
| App móvil (Jest) | 59 pasan · `tsc` y `expo lint` limpios · `expo-doctor` 20/20 |
| Web: `vue-tsc`, `vp check`, `npm run build`, Pint | limpios |
| Web E2E (Playwright, 1440 y 390 px, claro/oscuro) | 174 pasan (dev) · 80 pasan (build de producción) |

## Módulos

«Fase 1» = verificado por el agente responsable al cerrar su commit (24-nov). «17-nov» = verificación anterior a la Revisión RH. La columna «Pruebas» indica los archivos que lo cubren; el resultado consolidado de la suite se registra arriba.

| Módulo | Implementado | Pruebas | Último resultado conocido | Pendientes |
|---|---|---|---|---|
| Base de datos MariaDB (InnoDB forzado) | Sí | Migraciones + suite | 17-nov: OK (corrige el error 1071 original) | Ver `arquitectura/BASE_DE_DATOS.md` |
| Multiempresa y alcance por sucursal/supervisor | Sí | `Domain/TenancyIsolationTest`, `RouteBindingIsolationTest`, `Web/Operacion/IsolationTest` | 17-nov: OK | — |
| Sin marcajes automáticos (salida automática eliminada) | Sí | `Domain/NoAutomaticPunchesTest` | Fase 1: OK | Recalcular proyecciones al actualizar producción |
| Registro inalterable (triggers + HMAC encadenado + anclas) | Sí | `Domain/AttendanceLedgerTest` | 17-nov: OK | Anclas en almacenamiento WORM |
| Hora oficial: dispositivo, sincronización y recepción; alerta `clock_skew` > 10 min | Sí | `Api/ClockSkewTest`, `Mobile offlineAudit.test` | Fase 1: OK | — |
| Omisiones de checada, origen del marcaje, exportación de marcajes originales | Sí | `Web/Operacion/AttendanceOmissionsTest` | Fase 1: OK | — |
| Panel conforme a RH (por firmar, tiempo extra, sin conexión, por resolver, por sucursal) | Sí | `AttendanceOmissionsTest`, `ReportsAndDashboardTest` | Fase 1: OK | Etiqueta «Excedente» residual en el resumen personal |
| Motor legal versionado (48→40 h, 9→12 h extra) | Sí | `Domain/LegalEngineTest`, `WeeklyOvertimeTest` | Fase 1: OK | Puntos «requiere criterio jurídico» |
| Normativa sólo RH, «Verificada por RH» real, historial | Sí | `Web/Admin/LegalVersionsTest` | Fase 1: OK | Validación de la tabla 2027 por Jurídico (hoy «Sin verificar») |
| Horas extra por colaborador y semana; menores de 18 bloqueados | Sí | `Domain/WeeklyOvertimeTest` | Fase 1: OK | — |
| Festivos art. 74 precargados por año | Sí | `Domain/HolidayPreloadTest` | Fase 1: OK | Los oficiales no se editan desde la web |
| Rectificaciones en 5 pasos con evidencia, plazo en días hábiles, extemporáneas | Sí | `Rectifications/RectificationWorkflowTest`, `Web/Operacion/ApprovalsTest` | Fase 1: OK | Plazo y cierre de nómina por confirmar con RH |
| Firma electrónica encadenada (NIP o biometría), convenio y aviso | Sí | `Rectifications/SignaturesTest`, `Api/ContractTest`, `Mobile signatureFlow.test`, `documentsGate.test` | Fase 1: OK | Textos del convenio y del aviso los publica RH/Jurídico |
| Incidencias: catálogo cerrado, evidencia y autorización por tipo, cancelación con motivo | Sí | `Rectifications/IncidencesTest`, `Web/Admin/SettingsTest` | Fase 1: OK | Leyenda común no aplicada en la pantalla |
| Recepción de incidencias de People | Sí (sin conexión productiva) | `IncidencesTest` (fuente en memoria) | Fase 1: OK | Credenciales y contrato de People |
| Vacaciones (LFT art. 76) | Sí | `Api/RequestsTest`, `ApprovalsTest` | 17-nov: OK | — |
| Roles mínimos, separación de funciones, autoridad laboral con vigencia, revisión trimestral | Sí | `Web/Admin/RolesAndAccessTest`, `UsersAndImportTest` | Fase 1: OK | — |
| Doble factor obligatorio para roles administrativos | Sí | `Web/Admin/TwoFactorRequiredTest` | Fase 1: OK | — |
| Colaboradores: alta de registro incompleta, convenio adjunto, baja con revocación, importación validada | Sí | `TerminationRevokesAccessTest`, `UsersAndImportTest`, `SignaturesTest` | Fase 1: OK | Sin prueba específica de `RegistrationStatus`; fecha de nacimiento opcional en alta manual |
| Horarios: validación legal; cambios programados sólo a futuro con RH | Sí | `Web/Admin/SchedulesTest`, `ScheduleChangesTest` | Fase 1: OK | — |
| Dispositivos: 1 teléfono por colaborador, pendiente hasta RH, señales de integridad | Sí | `Api/DeviceBindingTest`, `DevicePendingTest`, `KioskTest` | Fase 1: OK | Sin prueba de `device_tampered` desde la app |
| Kiosco de sucursal (código de activación, número + NIP, cola offline, rostro opcional) | Sí | `Api/KioskTest`, `Mobile kiosk.test` | Fase 1: OK | Prueba en tableta real |
| Reportes institucionales (27), PDF/XLSX/CSV, folio, bitácora, NOI | Sí | `Reports/ReportsModuleTest`, `ReportSamplesTest`, `ReportsAndDashboardTest` | Fase 1: OK | Plantilla comercial; claves NOI; pausa de comida; worker de cola |
| Cumplimiento con pestañas; integridad diaria con alerta crítica; bitácora con valor anterior y nuevo | Sí | `Web/Operacion/ComplianceModuleTest` | Fase 1: OK | Anclaje externo WORM |
| Configuración: datos fiscales, paleta, correo de privacidad, aviso PDF, ubicación, sistema | Sí | `Web/Admin/ConfigurationB2Test`, `OrganizationOfficialDataTest`, `SettingsTest` | Fase 1: OK | Correo real y PDF del aviso los captura RH |
| Ubicación por puesto, pausa en descanso, corte de contingencia, depuración legal | Sí | `Api/LocationTrackingTest`, `LocationTest`, `Web/Operacion/LocationTest`, `Mobile trackingCycle.test`, `location.test` | Fase 1: OK | Plazo de 90 días por validar; puestos de campo por definir; prueba caminando en segundo plano |
| Acuse obligatorio de marcaje y confirmación de lectura | Sí | `Notifications/ReceiptsAndConfirmationTest`, `AnnouncementsTest` | Fase 1: OK (bandeja) | Push real requiere Firebase |
| Push FCM | Sí (sin credenciales) | `Notifications/FcmPushTest` (simulado) | 17-nov: OK simulado | Firebase en Android; iOS no operativo |
| App móvil: login, biometría del SO + firma HMAC, offline y sincronización | Sí | `Mobile punchFlow`, `queue`, `offlineAudit`, `signature`, `state` | 17-nov: OK en teléfono real (S20 FE); fase 1: Jest | Re-probar en teléfono real con la versión nueva |
| Verificación facial open source (YuNet + SFace + MiniFASNet) | Sí | `services/face/tests`, `Biometrics/*`, `Mobile face.test` | 17-nov: OK con imagen pública | Enrolar rostro real y calibrar umbrales; no certificado ISO 30107-3 |
| Identidad institucional (#225C54 / #AF8B51) | Configuración, reportes y app: sí; web: en integración (fase 2) | Revisión visual | Fase 1: app y reportes | Ícono adaptativo de Android aún en el verde del manual |

## Requiere credenciales o infraestructura externa

- **Firebase** (push): alta de `mx.mrlana.asistencias` (y variantes) y `google-services.json`; cuenta de servicio en el servidor. iOS: Apple Developer y APNs.
- **EAS, Google Play y App Store**: cuentas para builds firmados y publicación.
- **Servidor de producción** (Ubuntu + Nginx + PHP 8.4-FPM + MariaDB), workers (Supervisor) y cron del scheduler.
- **Claves de integridad** custodiadas fuera del servidor y almacenamiento WORM para las anclas.
- **Mr. Lana People**: URL, token y contrato.

## Requiere revisión jurídica

Ver `docs/legal/MATRIZ_CUMPLIMIENTO.md` §4 y `docs/cierre/CIERRE_TECNICO.md` §4 (normativa 2027 y STPS, conservación, aviso de privacidad, convenio de registro, plazo de firma).
