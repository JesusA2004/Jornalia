# API REST v1 — MR LANA Asistencias

API consumida por la app móvil (`Mobile/`) y por el modo kiosco. Todas las rutas viven bajo `/api/v1` (`Web/routes/api.php`). La fuente de verdad de los tipos es `packages/contracts/src/api.ts`; la especificación está en [`openapi.yaml`](./openapi.yaml). Las pruebas de contrato están en `Web/tests/Feature/Api/ContractTest.php`.

Los cambios del contrato son **aditivos**: no se quitan campos para no romper apps instaladas.

## Convenciones

| Aspecto | Regla |
|---|---|
| Fechas-hora | ISO-8601 UTC con `Z` (`2026-11-17T16:00:00Z`) |
| Fechas | `YYYY-MM-DD`, fecha civil en la zona del centro de trabajo |
| Duraciones | minutos enteros (`*_minutes`) |
| Importes / porcentajes | string decimal (`"25.00"`) |
| Respuesta correcta | `{ "data": T, "meta"?: PaginationMeta }` |
| Paginación | `?page=1&per_page=20` → `meta = { current_page, per_page, total, last_page }` |
| Error | `{ "message": string, "code": ApiErrorCode, "errors"?: { campo: string[] }, "legacy_code"?: ApiErrorCode }` |

## Mapa de endpoints

Bearer Sanctum salvo donde se indica. Límites: `api` 120/min por usuario; `auth` 10/min por IP y 5/min por correo+IP; `punches` (desafío, marcaje, sincronización) 20/min; `location` 30 lotes/min.

| Método y ruta | Uso | Notas |
|---|---|---|
| `GET /health` | Estado del servicio | Público |
| `POST /auth/login` · `POST /auth/refresh` · `POST /auth/logout` | Sesión y vínculo del dispositivo | Login público |
| `GET /profile` · `POST /acknowledgements/{id}` | Perfil y acuse de documentos | El acuse se conserva por compatibilidad; **no** cuenta como firma |
| `GET /attendance/today` · `GET /attendance/policy` · `GET /attendance/history` · `GET /attendance/punches/{uuid}` | Jornada, política, historial, comprobante | |
| `POST /attendance/challenge` · `POST /attendance/punches` · `POST /sync/punches` | Marcaje firmado y sincronización | Ver [Firma de marcajes](#firma-de-marcajes) |
| `GET/POST /rectifications` · `GET /rectifications/{id}` | Rectificaciones propias (`?stage=`) | **Nuevo:** `stage`, original vs propuesta, evidencias, plazo. `POST` multipart con `evidence[]` obligatoria |
| `POST /rectifications/{id}/sign` | Firma del colaborador | **Nuevo.** 20/min |
| `POST /rectifications/{id}/decline` | El colaborador no acepta la corrección (`reason`) | **Nuevo** |
| `POST /profile/signature-pin` | Definir o cambiar el NIP (`pin`, `current_pin?`) | **Nuevo.** 10/min |
| `GET /documents/pending` · `GET /documents` | Convenio y aviso pendientes; constancias firmadas | **Nuevo** |
| `POST /documents/{id}/sign` · `GET /documents/{id}/pdf` | Firma (idempotente) y PDF | **Nuevo.** 20/min |
| `GET /files/documents/{id}` · `GET /files/evidence/{id}` | PDF y evidencia con URL firmada temporal (10 min), sin Bearer | **Nuevo.** 60/min |
| `GET /incidence-types` · `GET/POST /incidences` | Catálogo cerrado e incidencias propias | Evidencia según el tipo; incapacidad exige `folio` |
| `GET /vacations/balance` · `GET/POST /vacations` · `POST /vacations/{id}/cancel` | Vacaciones | |
| `GET /calendar` | Calendario del mes | |
| `GET /notifications` · `POST /notifications/{id}/read` · `POST /notifications/read-all` | Bandeja (incluye acuses de marcaje) | |
| `POST /announcements/{id}/read` | Confirmación de lectura de comunicados importantes | **Nuevo.** Idempotente; `404` si no existe, fue retirado o no te fue enviado |
| `GET /devices` · `POST /devices/push-token` | Dispositivos y token FCM | |
| `POST /location/points` · `GET /location/state` | Seguimiento durante la jornada | Rechazos por punto: `outside_window`, `tracking_disabled`, `on_break`, `contingency_cutoff`, … |
| `GET /face-verification/status` · `POST /face-verification/challenge` · `POST /face-verification/verify` | Verificación facial 1:1 | 20/min; ver [Biometría](../seguridad/BIOMETRIA.md) |
| `POST /kiosk/activate` | Activa un kiosco con código de un solo uso (48 h) | **Nuevo.** Público, 10/min; devuelve el token del kiosco una sola vez |
| `GET /kiosk/status` · `POST /kiosk/punches` · `POST /kiosk/sync` · `POST /kiosk/face/challenge` · `POST /kiosk/face/verify` | Operación del kiosco | **Nuevo.** Token del kiosco (no de usuario), 120/min |

### Cambios de la Revisión RH

| Cambio | Detalle |
|---|---|
| `GET /attendance/policy` | Agrega `location_tracking` (`on_punch` \| `during_shift`, parámetro del puesto), `tracking_paused_states` (`['break', 'meal']`) y `location_contingency_minutes`. |
| `POST /attendance/punches` y `/sync/punches` | Aceptan `synced_at` opcional. El servidor guarda `received_at`; diferencia > 10 min ⇒ alerta `clock_skew` en Cumplimiento. `Punch` expone `received_at` y `synced_at`. |
| `Workday.auto_clock_out_at` | **Deprecado.** Siempre `null` (la salida automática se eliminó). Se conserva opcional para apps instaladas. |
| `Workday.after_cutoff_minutes` | **Deprecado.** Siempre `0`: ningún tiempo registrado se descarta. |
| `PunchSource = 'system'`, anomalía `auto_clock_out` | Sólo en marcajes históricos («Generado por sistema (histórico)»). Ningún proceso los genera. |
| `PunchSource = 'kiosk'` | Marcajes del kiosco de sucursal. |
| Aviso `attendance.punch_receipt` | Acuse obligatorio de cada marcaje en la bandeja, aunque no haya push. |
| `POST /acknowledgements/{id}` | Se conserva, pero el convenio y el aviso de privacidad sólo cuentan con `POST /documents/{id}/sign`. |

## Autenticación

1. La app genera un `device_uuid` en la primera ejecución y lo guarda en SecureStore.
2. `POST /auth/login` con correo, contraseña y `DeviceInfo` (incluye `integrity` opcional: `rooted`, `jailbroken`, `emulator`, `mock_location`):
   - Devuelve un token Sanctum (`Bearer`) llamado `device:{device_uuid}`, con caducidad (`token_expires_at`; por omisión 14 días, `MOBILE_TOKEN_TTL` en minutos).
   - `device_secret` (base64, 32 bytes) se entrega **una sola vez**: al vincular por primera vez o al re-vincular un dispositivo revocado. Después es `null`. Se guarda en SecureStore con `requireAuthentication: true`.
   - Un dispositivo nuevo queda `pending` hasta que RH lo autoriza. `DEVICE_AUTO_APPROVE_FIRST=true` autoaprueba el primero (no recomendado en producción). Un cambio de teléfono siempre queda pendiente y avisa a RH.
   - Un teléfono ya vinculado a otro colaborador → `409 device_in_use`.
   - Credenciales inválidas → `422 validation_failed` con mensaje genérico. Usuario inactivo → `403 forbidden`. Se audita cada intento.
3. `POST /auth/refresh` rota el token (el anterior deja de funcionar).
4. `POST /auth/logout` revoca el token actual (`204`).
5. Revocar un dispositivo desde la web revoca sus tokens: la app recibe `401` y debe iniciar sesión de nuevo (re-vincula con un secreto nuevo, en `pending`).

Señales de integridad del teléfono: cabecera `X-Device-Integrity` (lista separada por comas). Generan alertas en Cumplimiento; no bloquean el marcaje.

## Firma de marcajes

```
canonical = client_uuid|type|device_time|nonce|device_uuid      (nonce vacío si es null)
signature = hex(HMAC_SHA256(base64decode(device_secret), canonical))
```

- `device_time` se firma **exactamente** como se envía (string crudo).
- En línea: `POST /attendance/challenge` → `nonce` de un solo uso (120 s). Reutilizado → `409 challenge_used`; expirado o desconocido → `409 challenge_expired`.
- Sin conexión: `captured_offline: true`, `nonce: null`. Se acepta si la firma es válida; si la política no lo permite o la sincronización es tardía, queda `flagged`. Ver [Operación sin conexión](../arquitectura/OPERACION_SIN_CONEXION.md).
- Firma inválida → `401 invalid_signature`.
- Dispositivo `pending` → `423 device_pending`; `revoked` → `403 device_revoked`; ajeno o desconocido → `403 device_unknown`.

## Firma electrónica del colaborador

Sirve para el convenio de registro (`registration_agreement`), el aviso de privacidad (`privacy_notice`) y cada rectificación (`rectification`).

```
POST /rectifications/{id}/sign   ó   POST /documents/{id}/sign
{ "method": "pin", "pin": "4826" }
{ "method": "biometric", "device_signature": "<hex>" }

device_signature = hex(HMAC_SHA256(base64decode(device_secret),
                   "sign|{purpose}|{signable_id}|{signature_payload_sha256}|{device_uuid}"))
```

- `signature_statement` (texto exacto) y `signature_payload_sha256` vienen en la rectificación en etapa `por_firmar` o en el documento pendiente. La app firma ese hash; nunca un booleano.
- El dispositivo lo toma el servidor del token. La biometría exige dispositivo aprobado.
- NIP: 4 a 6 dígitos, no triviales, guardado como hash; 5 fallos ⇒ bloqueo de 15 minutos. Errores de NIP, plazo vencido o etapa incorrecta → `422 validation_failed` con el campo en `errors`.
- La firma queda en `electronic_signatures` (append-only, encadenada): texto, SHA-256, dispositivo, IP, agente y fecha UTC.

Etapas de una rectificación: `por_autorizar` → `por_firmar` (con `signature_due_at`) → `por_validar` → `aplicada`, o `rechazada` (`rejection_stage`: `por_autorizar`, `por_firmar`, `por_validar`, `vencida`, `declinada`, `cancelada`). Flujo: [Rectificaciones y firma](../arquitectura/RECTIFICACIONES_Y_FIRMA.md).

## Kiosco de sucursal

1. RH o Sistemas (`devices.kiosk`) crea el kiosco en **Dispositivos › Kioscos** y obtiene un código de activación de un solo uso (vence en 48 h).
2. La tableta llama `POST /kiosk/activate` con el código y sus datos (`device_uuid`, plataforma, versión del SO y de la app, modelo) y recibe el token del kiosco **una sola vez**.
3. Cada marcaje: `POST /kiosk/punches` con `employee_number`, `pin` (NIP de firma), `type`, `client_uuid`, `device_time` y ubicación opcional. Idempotente por `client_uuid` (`201`, o `200` si es duplicado). Origen `kiosk`.
4. Si la política exige rostro: `POST /kiosk/face/challenge` y `/kiosk/face/verify` con número y NIP; sin verificación vigente → `422 face_verification_required` (`legacy_code: policy_violation`).
5. Sin conexión: cola local y `POST /kiosk/sync`.

Errores propios: `kiosk_unauthenticated` (401), `kiosk_activation_invalid`, `kiosk_invalid_credentials` (mismo mensaje para número inexistente o NIP incorrecto), `kiosk_wrong_branch`, `kiosk_employee_inactive`, `pin_locked` (423), `pin_not_set`.

## Idempotencia

`client_uuid` identifica el marcaje en el cliente. Reenviarlo devuelve el mismo marcaje: `201` la primera vez, `200` en los reintentos. `POST /sync/punches` procesa cada elemento por separado y responde `created`, `duplicate` o `rejected` (con su `ApiError`); un rechazo no aborta el lote (máx. 100 elementos).

Los marcajes son **append-only**: no hay rutas `PUT`/`PATCH`/`DELETE` (responden `404`/`405`). Las correcciones se solicitan con `POST /rectifications`.

## Errores

| HTTP | `code` | Cuándo |
|---|---|---|
| 401 | `unauthenticated` | Token ausente, inválido, expirado o revocado |
| 401 | `invalid_signature` | Firma HMAC no válida |
| 401 | `kiosk_unauthenticated` | Kiosco no activado o revocado |
| 403 | `forbidden` | Usuario inactivo o sin organización |
| 403 | `no_employment` | Sin relación laboral vigente |
| 403 | `device_revoked`, `device_unknown` | Dispositivo revocado o no vinculado |
| 404 | `not_found` | Recurso inexistente o de otro usuario u organización |
| 409 | `challenge_used`, `challenge_expired`, `invalid_sequence`, `device_in_use` | Desafío, secuencia o teléfono ajeno |
| 409 | `immutable_record` | Intento de alterar un registro inmutable |
| 409 | `documents_pending` | Marcaje en línea sin convenio de registro o aviso de privacidad vigentes firmados (`legacy_code: policy_violation`). Los marcajes sin conexión no se rechazan por esta causa: se registran con alerta `punch_without_agreement` |
| 422 | `validation_failed` | Validación (incluye `errors`) |
| 422 | `location_required`, `outside_geofence`, `policy_violation`, `face_verification_required` | Política de asistencia |
| 423 | `device_pending`, `pin_locked` | Dispositivo aún no autorizado; NIP bloqueado (kiosco) |
| 429 | `rate_limited` | Límite de solicitudes |
| 503 / 409 / 429 / 422 | `face_unavailable`, `face_not_enrolled`, `face_locked`, `face_challenge_invalid`, `face_challenge_expired` | Verificación facial |

## Aislamiento y privacidad

- Todo se resuelve en el servidor para el colaborador autenticado en su organización activa; recursos de otros usuarios u organizaciones responden `404`.
- Nunca se exponen el secreto del dispositivo, el token push, CURP/RFC/NSS ni la IP.
- El recibo del kiosco sólo muestra el primer nombre de quien marcó.

## Ejemplos curl

```bash
API=http://127.0.0.1:8000/api/v1

curl -s $API/health

# Inicio de sesión (sustituya correo y contraseña por los de un usuario de pruebas)
curl -s -X POST $API/auth/login -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{
  "email": "colaborador@ejemplo.test", "password": "********",
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

# Rectificación con evidencia obligatoria (multipart)
curl -s -X POST $API/rectifications -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -F date=2026-11-16 -F kind=missing_punch -F proposed_type=clock_in -F proposed_time=2026-11-16T15:00:00Z \
  -F 'reason=Olvidé marcar la entrada' -F 'evidence[]=@constancia.pdf'

# Pendientes de firma y firma con NIP
curl -s "$API/rectifications?stage=por_firmar" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
curl -s -X POST $API/rectifications/42/sign -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -d '{"method":"pin","pin":"4826"}'

# Convenio y aviso pendientes
curl -s $API/documents/pending -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

## Servidor local para la app

```bash
cd Web && php artisan serve --host=0.0.0.0 --port=8000
```

La app en un teléfono físico debe apuntar a `http://<IP-LAN-del-equipo>:8000/api/v1`, o usar `adb reverse tcp:8000 tcp:8000` con `EXPO_PUBLIC_API_URL=http://localhost:8000/api/v1`. En el emulador Android el valor por omisión es `http://10.0.2.2:8000/api/v1`.
