# Cumplimiento: integridad, bitácora, normativa y conservación

Revisión RH (pantallas 04, 14–16 y 19). Estado real al 24-nov-2026.

## Módulo «Cumplimiento»

Un solo módulo con tres pestañas (menú lateral sin «Integridad» ni «Auditoría»):

| Pestaña | Ruta | Permiso |
|---|---|---|
| Alertas legales | `/cumplimiento` | `compliance.view` (atender: `compliance.resolve`) |
| Integridad del registro | `/cumplimiento/integridad` | `integrity.view` (verificar/anclar: `integrity.verify`) |
| Bitácora de cambios | `/cumplimiento/bitacora` | `audit.view` |

Las rutas anteriores `/integridad` y `/auditoria` redirigen (conservando la cadena de consulta).

## Verificación y anclaje automáticos

- `asistencias:integridad diaria` (03:30): ancla y verifica las cadenas de marcajes y ajustes. `asistencias:integridad anclar` corre cada hora. El botón «Verificar ahora» es opcional.
- Si la verificación encuentra alteraciones o el anclaje falla: alerta **crítica** en Cumplimiento (`integrity_failed` / `integrity_anchor_failed`, sin colaborador) y aviso a RH (`compliance.resolve`) y Sistemas (`integrity.verify`), una vez por día y tipo (`App\Domain\Compliance\IntegrityMonitor`).
- Cada ejecución queda en la bitácora (`integrity.verified`, `integrity.anchored`, `integrity.anchor_failed`) indicando si fue automática o manual.

### Anclaje externo (WORM) — pendiente de infraestructura

Las anclas se firman con HMAC (`INTEGRITY_ANCHOR_KEY`) y se escriben en la tabla `integrity_anchors` y en el disco `INTEGRITY_ANCHOR_DISK` (por omisión `anchors`, **local**). Un anclaje local NO es externo: la pantalla lo indica como «Local · almacenamiento WORM pendiente». Para que sea externo real:

1. Crear un bucket S3 (u otro almacenamiento compatible) con **Object Lock** en modo *compliance* y retención ≥ plazo de conservación.
2. Definir un disco `s3` en `config/filesystems.php` con credenciales de escritura sin permiso de borrado y apuntar `INTEGRITY_ANCHOR_DISK` a ese disco.
3. Custodiar `INTEGRITY_ANCHOR_KEY` fuera del servidor de base de datos.

Hasta que eso exista no debe declararse que el anclaje es externo.

## Bitácora inalterable

`audit_logs` es append-only (trait `Immutable` + triggers `BEFORE UPDATE/DELETE`). Cada registro guarda usuario, fecha y hora (UTC), acción, **valor anterior (`old_values`) y nuevo (`new_values`)**, IP, agente y dispositivo de la app (`device_id`) cuando la acción viene de la API. `AuditLogger` copia `before`/`after` del contexto a esas columnas.

## Alertas mínimas

| Regla | Origen |
|---|---|
| Jornada diaria total > máximo (`daily_total_exceeded`) y semanal > máximo (`weekly_ordinary_exceeded`) | `ComplianceEvaluator` |
| Tiempo extra cerca del tope semanal ≥ 80 % (`overtime_near_limit`) o por encima (`overtime_over_limit`) | `ComplianceEvaluator` |
| Menor de 18 con tiempo extra (`minor_overtime`) | `ComplianceEvaluator` |
| Falta de descanso semanal (`no_weekly_rest`) | `ComplianceEvaluator` |
| Omisión sin rectificar por más de 2 días (`unrectified_omission`) | `cumplimiento:omisiones` (diario 07:00, `OmissionMonitor`) |
| Diferencia de hora del dispositivo > 10 min (`clock_skew`) | `ClockSkewMonitor` (A1) |

## Normativa (fuente única)

`legal_versions` por año (2026 48/9, 2027 46/9, 2028 44/10, 2029 42/11, 2030 40/12; diarios 8/7/7.5 h; 4 h extra por día en máx. 4 días; triples hasta 4 h/sem; 12 h totales; sin tiempo extra para menores). `LegalFramework::for(fecha)` resuelve la versión vigente, por lo que el cambio el 1 de enero de cada tramo es automático.

- Edición y verificación sólo con `settings.legal_manage` (RH) en Configuración › Normativa; sólo versiones vigentes o futuras.
- Nunca se precarga como verificada: la migración `2026_11_24_130000` regresa a «Sin verificar» toda versión sin usuario y fecha de validación; el seeder no la marca ni sobrescribe ediciones de RH.
- «Verificada por RH» guarda `verified_by` y `verified_at` reales; cualquier edición posterior la regresa a «Sin verificar».
- Historial append-only en `legal_version_revisions` (antes/después) y en la bitácora.
- `LegalFramework::verificationFor(fecha)` devuelve la leyenda «Normativa en verificación» / «Verificada por RH» para Horas extra y Cumplimiento.

## Ubicación y conservación de recorridos

- Parámetro por puesto (`positions.location_tracking`): `on_punch` (por omisión) o `during_shift`.
- Con `during_shift` sólo se aceptan puntos con jornada abierta, fuera de descanso/comida y antes del corte de contingencia (fin de turno + margen, 60 min por omisión; sin turno: entrada + 12 h + margen). Nunca se genera una salida.
- **Depuración legal (única excepción a «nada se elimina»)**: la LFPDPPP exige suprimir datos personales al concluir la finalidad. `ubicacion:depurar` (diario 02:45) borra físicamente sólo `location_points` con más días que el plazo configurado (90 días iniciales, **pendiente de validar por Jurídico**). El trigger de BD rechaza cualquier DELETE salvo que la sesión active `@jornalia_location_purge = 1`, cosa que sólo hace `LocationRetention`; UPDATE sigue prohibido. Cada depuración queda en la bitácora (`location.purged`). Los marcajes y su ubicación de evidencia nunca se depuran.
- Registros de asistencia: se conservan (5 años; ver `database/data/legal/parametros_legales.json`), sin borrado.
