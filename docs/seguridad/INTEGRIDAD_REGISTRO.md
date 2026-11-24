# Integridad del registro electrónico de asistencias

Modelo de amenazas y controles técnicos. Operación del módulo Cumplimiento (alertas, rutina diaria, bitácora, conservación): [Integridad y conservación](../cumplimiento/INTEGRIDAD_Y_CONSERVACION.md).

## Garantías

| Amenaza | Control | Resultado |
|---|---|---|
| Usuario (cualquier rol, incluido Sistemas) intenta editar o borrar un marcaje | No existen rutas ni UI de edición; el modelo `Punch` lanza `ImmutableRecordException` | Imposible desde la aplicación |
| Un proceso del sistema «completa» una jornada | No existe ningún proceso que cree marcajes (la salida automática se eliminó); la salida faltante es omisión | Probado en `NoAutomaticPunchesTest` |
| Código o consulta directa `UPDATE`/`DELETE` | Triggers `*_no_update` / `*_no_delete` en `attendance_punches`, `attendance_adjustments`, `electronic_signatures`, `document_acknowledgements`, `audit_logs`, `integrity_anchors`, `legal_version_revisions` y `location_points` (este último sólo admite la depuración legal programada) | Rechazado por la BD |
| DBA desactiva triggers y altera un registro | Sello HMAC encadenado (`hash`, `previous_hash`, `sequence`) en marcajes, ajustes y firmas electrónicas | Detectado por `asistencias:integridad verificar` |
| DBA borra o inserta registros | Huecos de secuencia, enlace roto o sello inválido | Detectado |
| DBA con acceso a la clave reescribe toda la cadena | Anclas firmadas con `INTEGRITY_ANCHOR_KEY` escritas fuera de la BD (disco `anchors`; recomendado S3 con Object Lock o servidor separado) | Detectado contra anclas ya emitidas |
| Repetición de una petición de marcaje (replay) | Nonce de un solo uso (120 s) + idempotencia por `client_uuid` | Rechazado o devuelto como duplicado |
| App falsifica `biometric_success=true` | La API exige firma HMAC con el secreto del dispositivo, que el SO sólo libera tras autenticación | Sin firma válida no hay marcaje |
| Un compañero marca por otro | Vínculo uno a uno colaborador–teléfono; teléfono nuevo pendiente hasta que RH lo autoriza; en kiosco, número de empleado + NIP (bloqueo tras 5 intentos) y verificación facial opcional | Rechazado o queda como omisión |
| Reloj del teléfono alterado | Hora oficial = recepción en servidor; alerta `clock_skew` si la diferencia supera 10 min | Alertado, nunca corregido en silencio |
| Ubicación simulada o teléfono con root | Señales del SO (`mock_location`, `rooted`, `jailbroken`, `emulator`) ⇒ alerta en Cumplimiento | Alertado (detección no infalible) |
| Corrección sin conformidad del colaborador | Rectificación aplicada sólo con `ElectronicSignature` válida (sello verificado) y validación de RH | Sin firma no hay ajuste |
| Alteración de la normativa | `legal_versions` con checksum, edición sólo por RH, historial append-only y bitácora | Detectado y trazable |

**Limitación declarada:** ninguna base de datos convencional es absolutamente inmutable frente a un administrador con privilegios totales sobre servidor, claves y almacenamiento de anclas. El diseño hace **detectable** la manipulación, siempre que las claves y las anclas se custodien separadas (personas y sistemas distintos).

## Claves

| Variable | Uso | Si se pierde o cambia |
|---|---|---|
| `INTEGRITY_CHAIN_KEY` | Sella cada eslabón (`hash = HMAC_SHA256(clave, previous_hash ∥ "\n" ∥ payload canónico)`). Si no se define, se usa `APP_KEY`. | La cadena histórica deja de poder verificarse. **Nunca** la rote ni regenere `APP_KEY` en producción sin un procedimiento de recertificación. |
| `INTEGRITY_ANCHOR_KEY` | Firma las anclas. Debe ser distinta de `APP_KEY`. | Las anclas emitidas dejan de poder validarse. |

Generar (64 hex): `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Respaldarlas cifradas fuera del servidor y en un gestor de secretos.

## Operación

```bash
php artisan asistencias:integridad anclar      # programado cada hora
php artisan asistencias:integridad verificar   # manual; código de salida ≠ 0 si hay alteración
php artisan asistencias:integridad diaria      # programado 03:30: anclar + verificar + alerta crítica si falla
php artisan asistencias:integridad verificar --organizacion=ID
```

Recomendaciones de producción:

1. Usuario de BD de la aplicación **sin** privilegios `TRIGGER`, `SUPER`, `DROP`, `ALTER` (ver [Despliegue](../despliegue/DESPLIEGUE.md)).
2. `INTEGRITY_CHAIN_KEY` e `INTEGRITY_ANCHOR_KEY` en un gestor de secretos, con respaldo cifrado fuera del servidor.
3. Disco `anchors` en almacenamiento WORM (S3 Object Lock en modo *compliance*) o en otro servidor. **Pendiente de infraestructura.**
4. Vigilar las alertas críticas de integridad (llegan a RH y Sistemas).

## Rectificaciones

El marcaje original nunca cambia. Una rectificación **aplicada** (después de la autorización del jefe, la firma del colaborador y la validación de RH) crea un `attendance_adjustment` (agregar, excluir del cálculo o reconocer otra hora), también encadenado e inmutable. Las rectificaciones aprobadas antes de existir la firma quedaron «Aplicadas» con la marca «Previa al flujo» (`legacy_without_signature`), sin firma retroactiva. Los reportes muestran el marcaje original, la propuesta, la firma, el horario reconocido, el responsable y la evidencia. Flujo completo: [Rectificaciones y firma](../arquitectura/RECTIFICACIONES_Y_FIRMA.md).
