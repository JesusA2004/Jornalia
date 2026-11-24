# Cumplimiento: alertas, integridad, bitácora y conservación

Revisión RH, pantallas 14–16 y reglas generales 4, 5 y 7. Modelo de amenazas y garantías técnicas: [Integridad del registro](../seguridad/INTEGRIDAD_REGISTRO.md). Normativa y cálculos: [Motor legal](../legal/MOTOR_LEGAL.md).

## Módulo «Cumplimiento»

Un solo módulo con tres pestañas. El menú lateral ya no tiene «Integridad» ni «Auditoría».

| Pestaña | Ruta | Permiso |
|---|---|---|
| Alertas legales | `/cumplimiento` | `compliance.view` (atender: `compliance.resolve`) |
| Integridad del registro | `/cumplimiento/integridad` | `integrity.view` (verificar o anclar: `integrity.verify`) |
| Bitácora de cambios | `/cumplimiento/bitacora` | `audit.view` |

Las rutas anteriores `/integridad` y `/auditoria` redirigen y conservan la cadena de consulta. Integridad y Bitácora: RH y Sistemas, y en sólo lectura Contraloría y Autoridad laboral.

## Alertas legales

| Regla | Código | Origen |
|---|---|---|
| Jornada diaria total superior al máximo | `daily_total_exceeded` | `ComplianceEvaluator` |
| Jornada ordinaria semanal superior al tope | `weekly_ordinary_exceeded` | `ComplianceEvaluator` |
| Tiempo extra ≥ 80 % del tope semanal o por encima | `overtime_near_limit` / `overtime_over_limit` | `ComplianceEvaluator` |
| Menor de 18 con tiempo extra, trabajo nocturno o (menor de 16) más de 6 h | `minor_overtime`, `minor_night_work`, `minor_daily_limit` | `ComplianceEvaluator` |
| Falta de descanso semanal | `no_weekly_rest` | `ComplianceEvaluator` |
| Omisión de checada sin rectificar por más de 2 días | `unrectified_omission` | `cumplimiento:omisiones` (diario 07:00, `OmissionMonitor`); se cierra al rectificar |
| Diferencia de hora del dispositivo > 10 min | `clock_skew` | `ClockSkewMonitor` |
| Ubicación simulada o teléfono alterado | `mock_location`, `device_tampered` | `DeviceIntegrityMonitor` |
| Falla de verificación o de anclaje | `integrity_failed`, `integrity_anchor_failed` | `IntegrityMonitor` |
| Revisión trimestral de accesos abierta | `user_access_review` | `UserAccessService` |

RH atiende cada alerta con nota (`compliance.resolve`); nada se borra.

## Verificación y anclaje automáticos

- `asistencias:integridad diaria` (03:30): ancla y verifica las cadenas de marcajes y ajustes. `asistencias:integridad anclar` corre cada hora. El botón «Verificar ahora» es opcional.
- Si la verificación encuentra alteraciones o el anclaje falla: alerta **crítica** sin colaborador y aviso (bandeja + push) a quienes tienen `compliance.resolve` (RH) o `integrity.verify` (Sistemas), una vez por día y tipo.
- Cada ejecución queda en la bitácora (`integrity.verified`, `integrity.anchored`, `integrity.anchor_failed`) indicando si fue automática o manual.

### Anclaje externo (WORM) — pendiente de infraestructura

Las anclas se firman con HMAC (`INTEGRITY_ANCHOR_KEY`) y se escriben en la tabla `integrity_anchors` y en el disco `INTEGRITY_ANCHOR_DISK` (por omisión `anchors`, **local**, ruta `INTEGRITY_ANCHOR_PATH`). Un anclaje local NO es externo: la pantalla lo indica como «Local · almacenamiento WORM pendiente». Para que sea externo:

1. Crear un bucket S3 (u otro compatible) con **Object Lock** en modo *compliance* y retención ≥ plazo de conservación.
2. Definir el disco en `config/filesystems.php` con credenciales de escritura sin permiso de borrado y apuntar `INTEGRITY_ANCHOR_DISK` a ese disco.
3. Custodiar `INTEGRITY_ANCHOR_KEY` fuera del servidor de base de datos.

Hasta que eso exista no debe declararse que el anclaje es externo.

## Bitácora inalterable

`audit_logs` es append-only (trait `Immutable` + triggers `BEFORE UPDATE/DELETE`). Cada registro guarda usuario, fecha y hora (UTC), acción, **valor anterior (`old_values`) y nuevo (`new_values`)**, IP, agente y dispositivo de la app (`device_id`) cuando la acción viene de la API. `AuditLogger` copia `before`/`after` del contexto a esas columnas. Se registran, entre otros: cambios de roles y permisos, normativa, configuración, documentos, horarios, decisiones de rectificaciones e incidencias, exportaciones de reportes (con folio) y depuraciones de ubicación.

## Conservación

| Registro | Regla | Estado |
|---|---|---|
| Marcajes, ajustes, firmas, bitácora, anclas | Nunca se borran (triggers + `NoPhysicalDeletes`). Referencia: 5 años o 1 año después de la baja, lo que sea mayor (`database/data/legal/parametros_legales.json`). | Plazo final pendiente de Jurídico; hoy no existe purga. |
| Recorridos de ubicación (`location_points`) | Depuración automática al vencer el plazo (90 días iniciales). Única excepción a «nada se elimina». | Plazo pendiente de Jurídico. Ver [Geolocalización](../arquitectura/GEOLOCALIZACION.md). |
| Plantilla facial | Revocación o cancelación ARCO (vaciado de la plantilla); el registro se conserva. | Vaciado tras la baja: manual. Ver [Biometría](../seguridad/BIOMETRIA.md). |

**Configuración › Sistema** muestra las políticas de conservación vigentes, el estado de notificaciones e integraciones y los parámetros de rectificación.

## Pruebas

`Web/tests/Feature/Web/Operacion/ComplianceModuleTest.php` (pestañas y redirecciones, acceso de Contraloría y autoridad, rutina diaria, alerta crítica por alteración y por anclaje, bitácora con valor anterior y nuevo, omisiones sin rectificar), `Web/tests/Feature/Domain/AttendanceLedgerTest.php` (triggers y detección de alteraciones).
