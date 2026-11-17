# Integridad del registro electrónico de asistencias

## Garantías

| Amenaza | Control | Resultado |
|---|---|---|
| Usuario (cualquier rol) intenta editar o borrar un marcaje | No existen rutas ni UI de edición; el modelo `Punch` lanza `ImmutableRecordException` | Imposible desde la aplicación |
| Código o consulta directa `UPDATE`/`DELETE` | Triggers `attendance_punches_no_update/no_delete` (y en ajustes, acuses, auditoría y anclas) | Rechazado por la BD |
| DBA desactiva triggers y altera un registro | Sello HMAC encadenado (`hash`, `previous_hash`, `sequence`) | Detectado por `asistencias:integridad verificar` |
| DBA borra o inserta registros | Huecos de secuencia, enlace roto o sello inválido | Detectado |
| DBA con acceso a la clave reescribe toda la cadena | Anclas firmadas con `INTEGRITY_ANCHOR_KEY` escritas fuera de la BD (disco `anchors`, recomendado S3 con Object Lock o servidor separado) | Detectado contra anclas ya emitidas |
| Repetición de una petición de marcaje (replay) | Nonce de un solo uso + idempotencia por `client_uuid` | Rechazado o devuelto como duplicado |
| App falsifica `biometric_success=true` | La API exige firma HMAC con el secreto del dispositivo, que el SO sólo libera tras autenticación | Sin firma válida no hay marcaje |

**Limitación declarada:** ninguna base de datos convencional es absolutamente inmutable frente a un administrador
con privilegios totales sobre servidor, claves y almacenamiento de anclas. El diseño hace **detectable** la
manipulación, siempre que las claves y las anclas se custodien separadas (personas y sistemas distintos).

## Operación

```bash
php artisan asistencias:integridad anclar      # programado cada hora
php artisan asistencias:integridad verificar   # programado diario 03:30; código de salida ≠ 0 si hay alteración
```

Recomendaciones de producción:

1. Usuario de BD de la aplicación **sin** privilegios `TRIGGER`, `SUPER`, `DROP`, `ALTER` (ver `docs/despliegue/DESPLIEGUE.md`).
2. `INTEGRITY_CHAIN_KEY` e `INTEGRITY_ANCHOR_KEY` en un gestor de secretos, con respaldo cifrado fuera del servidor.
3. Disco `anchors` en almacenamiento WORM (S3 Object Lock en modo *compliance*) o en otro servidor.
4. Alertar si la verificación diaria falla.

## Rectificaciones

El marcaje original nunca cambia. Una rectificación aprobada crea un `attendance_adjustment` (agregar, excluir del
cálculo o reconocer otra hora), también encadenado e inmutable. Los reportes muestran: marcaje original,
rectificación, horario reconocido, estado de aprobación, responsable y justificación.
