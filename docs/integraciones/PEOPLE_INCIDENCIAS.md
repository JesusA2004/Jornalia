# People → Asistencias: incidencias, permisos, incapacidades y vacaciones

Revisión RH (pantalla 06): **People es el origen único**. Autoriza y envía al reloj la
incidencia aprobada; en Asistencias no se vuelve a capturar ni a autorizar.

> **Estado: sin conexión productiva.** Sin `PEOPLE_API_URL` y `PEOPLE_API_TOKEN` el comando
> no se ejecuta y Configuración › Incidencias muestra «Sin configurar». Probado sólo con
> fuentes en memoria (`tests/Feature/Rectifications/IncidencesTest.php`).

## Contrato esperado

`GET {PEOPLE_API_URL}/incidences?status=approved&since=AAAA-MM-DD&page=N` (Bearer)

```json
{ "data": [ {
    "id": "X1",                      // identificador estable en People (obligatorio)
    "kind": "incidence",             // incidence | vacation
    "employee_external_id": "P-77",  // o employee_number
    "type_code": "INC_EG",           // = incidence_types.external_code o code del catálogo
    "starts_on": "2026-11-10", "ends_on": "2026-11-12",
    "folio": "AB1234",               // obligatorio para incapacidades
    "reason": "…",
    "status": "approved"             // approved | cancelled
} ], "next_page_url": null }
```

## Reglas

- Idempotencia: `(organization_id, external_source = people, external_id)` único en
  `incidences` y `vacation_requests`. Un registro ya recibido no se duplica.
- `status = cancelled` de un registro ya recibido ⇒ se cancela aquí con motivo
  «Cancelada en Mr. Lana People» (nunca se borra) y se recalculan los días.
- Se registran aprobadas (`source = people`) y se recalculan Asistencias y Calendario.
- Errores por registro (colaborador o tipo sin equivalencia, fechas inválidas, incapacidad
  sin folio) quedan en `integration_runs.conflicts` (origen `people_incidences`); la corrida
  queda `partial`. Falla de conexión ⇒ `failed` sin cambios.
- `since` = inicio de la última corrida exitosa menos un día.
- La equivalencia de tipos la captura RH en Configuración › Incidencias («Código en People»).
- Las omisiones de checada NO llegan de People: siempre se resuelven con rectificación firmada.

## Operación

- `php artisan people:incidencias [--organizacion=ID]` (programado cada hora en `routes/console.php`).
- Botón «Sincronizar ahora» en Configuración › Incidencias (permiso `integrations.sync`).
