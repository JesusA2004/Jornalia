# Integración con Mr. Lana People

Mr. Lana People es el **origen** de altas, bajas, departamentos, puestos y sucursales
(revisión RH, regla general 12). MR LANA Asistencias **consume** su API; nunca accede a la
base de datos de People ni escribe en ella.

> Estado: el cliente y el contrato están implementados y probados con dobles de prueba
> (`Http::fake` y fuentes en memoria). **No existe conexión productiva**: hasta que Sistemas
> capture las credenciales, la pantalla «Integraciones» muestra «Sin configurar» y la
> sincronización no realiza ninguna llamada. La parte de **incidencias** (permisos,
> vacaciones, incapacidades) se documenta y mantiene por separado.

## Configuración

| Variable | Uso |
|---|---|
| `PEOPLE_API_URL` | URL base, p. ej. `https://people.mr-lana.com/api/v1` (sin `/` final). |
| `PEOPLE_API_TOKEN` | Token de servicio (Bearer) de sólo lectura, emitido por People para Asistencias. |

Ambas se leen en `config/services.php` (`services.people.*`). Sin cualquiera de las dos, la
integración queda deshabilitada.

## Autenticación y transporte

- `Authorization: Bearer <PEOPLE_API_TOKEN>`, `Accept: application/json`, sólo HTTPS.
- El token debe ser de **sólo lectura** y limitado a los recursos de esta página.
- Tiempo máximo por petición: 20 s; 2 reintentos con 500 ms de espera ante fallas de red.

## Paginación (todos los recursos)

`GET {PEOPLE_API_URL}/{recurso}?page=N` (N inicia en 1) responde:

```json
{ "data": [ { … } ], "next_page_url": "https://…?page=2" }
```

`next_page_url` es `null` en la última página. Límite de seguridad: 200 páginas por recurso.
Se recomiendan 100–500 registros por página.

## Recursos requeridos

Todos los identificadores (`id`) son **estables e inmutables** en People: son la llave de
idempotencia (`external_source = people`, `external_id = id`).

### `GET /departments`

| Campo | Tipo | Obligatorio | Notas |
|---|---|---|---|
| `id` | string | sí | Identificador de People. |
| `code` | string | sí | Clave corta, `[A-Za-z0-9._-]`, máx. 40. Se guarda en mayúsculas. |
| `name` | string | sí | Máx. 160. |
| `parent_id` | string\|null | no | `id` de People del departamento superior. |
| `active` | bool | no | `false` desactiva el registro local (no se borra). |

### `GET /positions`

| Campo | Tipo | Obligatorio | Notas |
|---|---|---|---|
| `id`, `code`, `name`, `active` | | | Igual que departamentos. |
| `department_id` | string\|null | no | `id` de People del departamento. |

### `GET /branches`

| Campo | Tipo | Obligatorio | Notas |
|---|---|---|---|
| `id`, `code`, `name`, `active` | | | Igual que departamentos. |
| `region` | string\|null | no | Máx. 80. |
| `address` | string\|null | no | Domicilio del centro de trabajo, máx. 255. |
| `employer_registration` | string\|null | no | Registro patronal IMSS (8–20 letras/números). |

Asistencias **no** recibe ni sobrescribe coordenadas, geocercas ni el centro de trabajo de la
sucursal: se capturan localmente (geocerca sugerida de 100 a 150 m).

### `GET /employees`

Altas y datos de expediente (ya consumido por `HttpPeopleSource`):
`external_id`, `employee_number`, `first_name`, `last_name`, `second_last_name`, `email`,
`phone`, `birth_date` (AAAA-MM-DD), `curp`, `rfc`, `nss`, `starts_on`, `branch_code`,
`department_code`, `position_code`, `schedule_code`, `contract_type`, `work_modality`.

### `GET /terminations?since=AAAA-MM-DD`

Bajas registradas en People desde la fecha indicada (Asistencias envía el día anterior a su
última sincronización exitosa; sin `since` se esperan todas las bajas vigentes).

| Campo | Tipo | Obligatorio | Notas |
|---|---|---|---|
| `employee_id` | string | sí* | `id` de People del colaborador. |
| `employee_number` | string | sí* | Respaldo si no hay `employee_id`. *Uno de los dos. |
| `ends_on` | date | sí | Último día laborado (AAAA-MM-DD). |
| `reason` | string\|null | no | Motivo de la baja. |

Efecto: se registra la baja (`WorkforceService::terminate`), que desactiva ese mismo día el
acceso del colaborador a la organización (y, según la versión vigente, sus dispositivos y
sesiones de la app). Una baja ya aplicada se ignora (idempotente).

## Reglas de sincronización

1. **Idempotencia**: se vincula por `id` de People. Si un registro local con la misma clave
   aún no tiene vínculo, se vincula en lugar de duplicarse. Repetir la sincronización no
   crea registros nuevos.
2. **Información incompleta** (sin `id`, `code`, `name`, fecha o referencia válida): **no se
   crea** nada; el registro aparece en el detalle de la ejecución con su motivo.
3. **Conflictos** (clave local ya vinculada a otro `id`): no se sobrescribe; se reporta.
4. **Atomicidad**: un error de la fuente (HTTP ≠ 2xx, JSON sin `data`, falla de red) revierte
   todo el lote y la ejecución queda «Fallida».
5. **Bitácora**: cada ejecución queda en `integration_runs` (`source = people_structure`)
   con conteos y errores, y en la bitácora de auditoría.

## Errores esperados de People

| Código HTTP | Interpretación en Asistencias |
|---|---|
| 401 / 403 | Token inválido o sin permisos: ejecución «Fallida». |
| 404 | Recurso no publicado por People: ejecución «Fallida». |
| 429 | Exceso de peticiones: «Fallida»; reintentar más tarde. |
| 5xx | Error de People: «Fallida». |

Cuerpo sugerido de error: `{ "message": "…", "code": "…" }`.

## Frecuencia y operación

- Manual desde **Administración › Integraciones** con el permiso `integrations.sync`
  (Administrador de plataforma y Administrador RH). Existe «Simular», que no guarda cambios.
- Consulta del estado con `integrations.view`.
- Frecuencia recomendada una vez en producción: cada hora para bajas y diaria para estructura.
  La programación automática se habilitará cuando People confirme el contrato.

## Pendientes con el equipo de People

- Confirmar URL, emisión del token de servicio y los nombres exactos de campos.
- Confirmar que los `id` son estables y que las bajas se publican el mismo día.
- Definir el catálogo de claves de sucursal compartido con Asistencias.
