# API REST v1 — MR LANA Asistencias

API consumida por la app móvil (`Mobile/`). Todas las rutas viven bajo `/api/v1`.
La fuente de verdad de los tipos es `packages/contracts/src/api.ts`; la especificación
completa está en [`openapi.yaml`](./openapi.yaml). Las pruebas de contrato están en
`Web/tests/Feature/Api/ContractTest.php`.

## Convenciones

| Aspecto | Regla |
|---|---|
| Fechas-hora | ISO-8601 UTC con `Z` (`2026-11-17T16:00:00Z`) |
| Fechas | `YYYY-MM-DD`, fecha civil en la zona del centro de trabajo |
| Duraciones | minutos enteros (`*_minutes`) |
| Importes / porcentajes | string decimal (`"25.00"`) |
| Respuesta correcta | `{ "data": T, "meta"?: PaginationMeta }` |
| Paginación | `?page=1&per_page=20` → `meta = { current_page, per_page, total, last_page }` |
| Error | `{ "message": string, "code": ApiErrorCode, "errors"?: { campo: string[] } }` |

## Autenticación

1. La app genera un `device_uuid` en la primera ejecución y lo guarda en SecureStore.
2. `POST /auth/login` con correo, contraseña y `DeviceInfo`:
   - Devuelve un token Sanctum (`Bearer`) llamado `device:{device_uuid}`, con caducidad
     (`token_expires_at`, por defecto 14 días: `MOBILE_TOKEN_TTL`).
   - `device_secret` (base64, 32 bytes) se entrega **una sola vez**: al vincular por primera
     vez o al re-vincular un dispositivo revocado. Después es `null`. Guárdalo en SecureStore
     con `requireAuthentication: true`.
   - Un dispositivo nuevo queda `pending` hasta que RH lo autoriza (o se autoaprueba el primero si
     `DEVICE_AUTO_APPROVE_FIRST=true`).
   - Credenciales inválidas → `422 validation_failed` con mensaje genérico (no revela si el correo
     existe). Usuario inactivo → `403 forbidden`. Se audita cada intento.
3. `POST /auth/refresh` rota el token (el anterior deja de funcionar).
4. `POST /auth/logout` revoca el token actual (`204`).
5. Revocar un dispositivo desde la web revoca sus tokens: la app recibirá `401` y deberá
   iniciar sesión de nuevo (lo que re-vincula el dispositivo con un secreto nuevo, en `pending`).

Límites: `auth/login` 10/min por IP y 5/min por correo+IP; API general 120/min por usuario;
marcajes (`challenge`, `punches`, `sync`) 20/min; ubicación 30 lotes/min.

## Firma de marcajes

Cada marcaje prueba criptográficamente que el secreto del dispositivo se desbloqueó con la
biometría o credencial del SO:

```
canonical = client_uuid|type|device_time|nonce|device_uuid      (nonce vacío si es null)
signature = hex(HMAC_SHA256(base64decode(device_secret), canonical))
```

- `device_time` se firma **exactamente** como se envía (string crudo, p. ej. el resultado de
  `new Date().toISOString()`): el servidor no lo normaliza antes de verificar.
- En línea: pide `POST /attendance/challenge` → `nonce` de un solo uso (TTL 120 s) e inclúyelo.
  - nonce reutilizado → `409 challenge_used`; expirado o desconocido → `409 challenge_expired`.
- Sin conexión: `captured_offline: true`, `nonce: null`. Se acepta siempre que la firma sea
  válida; si la política no lo permite o la sincronización es tardía, queda `flagged`.
- Firma inválida → `401 invalid_signature` (nunca se acepta).
- Dispositivo `pending` → `423 device_pending`; `revoked` → `403 device_revoked`;
  ajeno o desconocido → `403 device_unknown`.

## Idempotencia

`client_uuid` identifica el marcaje en el cliente. Reenviarlo devuelve el mismo marcaje:
`201` la primera vez, `200` en los reintentos. `POST /sync/punches` procesa cada elemento por
separado y responde `created`, `duplicate` o `rejected` (con su `ApiError`); un rechazo no aborta
el lote (máx. 100 elementos).

Los marcajes son **append-only**: no hay rutas `PUT`/`PATCH`/`DELETE` (responden `404`/`405`).
Las correcciones se solicitan con `POST /rectifications`.

## Errores

| HTTP | `code` | Cuándo |
|---|---|---|
| 401 | `unauthenticated` | Token ausente, inválido, expirado o revocado |
| 401 | `invalid_signature` | Firma HMAC no válida |
| 403 | `forbidden` | Usuario inactivo / sin organización |
| 403 | `no_employment` | Sin relación laboral vigente |
| 403 | `device_revoked`, `device_unknown` | Dispositivo revocado o no vinculado |
| 404 | `not_found` | Recurso inexistente o de otro usuario/organización |
| 409 | `challenge_used`, `challenge_expired`, `invalid_sequence` | Desafío / secuencia |
| 409 | `immutable_record` | Intento de alterar un registro inmutable |
| 422 | `validation_failed` | Validación (incluye `errors`) |
| 422 | `location_required`, `outside_geofence`, `policy_violation` | Política de asistencia |
| 423 | `device_pending` | Dispositivo aún no autorizado |
| 429 | `rate_limited` | Límite de solicitudes |

## Aislamiento y privacidad

- Todo se resuelve en el servidor para el colaborador autenticado en su organización activa;
  recursos de otros usuarios u organizaciones responden `404`.
- Nunca se exponen el secreto del dispositivo, el token push, CURP/RFC/NSS ni la IP.

## Ejemplos curl

```bash
API=http://127.0.0.1:8000/api/v1

curl -s $API/health

# Inicio de sesión
curl -s -X POST $API/auth/login -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{
  "email": "colaborador@empresa.mx", "password": "********",
  "device": {"device_uuid": "6f1c1f4e-5b8e-4a49-9f39-3f3a4c7c2d10", "name": "Pixel", "platform": "android",
             "os_version": "15", "app_version": "1.0.0", "model": "Pixel 9", "biometric_available": true}
}'
TOKEN=...   # data.token

curl -s $API/attendance/today -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

# Desafío + marcaje firmado
NONCE=$(curl -s -X POST $API/attendance/challenge -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | jq -r .data.nonce)
CLIENT=$(uuidgen); TIME=$(date -u +%Y-%m-%dT%H:%M:%S.000Z); DEV=6f1c1f4e-5b8e-4a49-9f39-3f3a4c7c2d10
SIG=$(printf '%s' "$CLIENT|clock_in|$TIME|$NONCE|$DEV" \
  | openssl dgst -sha256 -mac HMAC -macopt hexkey:$(echo -n "$DEVICE_SECRET" | base64 -d | xxd -p -c 256) | awk '{print $2}')
curl -s -X POST $API/attendance/punches -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{
  \"client_uuid\": \"$CLIENT\", \"type\": \"clock_in\", \"device_time\": \"$TIME\", \"timezone\": \"America/Mexico_City\",
  \"device_uuid\": \"$DEV\", \"auth_method\": \"device_biometric\", \"nonce\": \"$NONCE\", \"signature\": \"$SIG\",
  \"captured_offline\": false, \"location\": null, \"face_verification_id\": null }"

# Historial paginado
curl -s "$API/attendance/history?from=2026-11-01&to=2026-11-17&per_page=10" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

# Rectificación con evidencia (multipart)
curl -s -X POST $API/rectifications -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -F date=2026-11-16 -F kind=missing_punch -F proposed_type=clock_in -F proposed_time=2026-11-16T15:00:00Z \
  -F 'reason=Olvidé marcar la entrada' -F evidence=@constancia.pdf
```

## Servidor local para la app

```bash
cd Web && php artisan serve --host=0.0.0.0 --port=8000
```

La app en un dispositivo físico debe apuntar a `http://<IP-LAN-del-equipo>:8000/api/v1`.
