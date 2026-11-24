# Operación sin conexión

Revisión RH, pantalla 01 (punto 4) y regla general 3. La app y el kiosco pueden marcar sin señal y sincronizar después, siempre que guarden la hora del dispositivo y el servidor registre la de recepción. Un marcaje sin conexión **nunca se descarta**: si algo no cuadra, queda «en revisión» y genera alerta.

## Marcaje sin conexión (app del colaborador)

1. La política (`GET /attendance/policy`) debe permitirlo (`allow_offline`; valor inicial `true`, con `offline_max_hours = 72`).
2. La app firma el marcaje con el secreto del dispositivo desbloqueado por la biometría del sistema operativo, con `nonce = null` y `captured_offline = true`.
3. Se guarda en la cola local cifrada (SQLite con SQLCipher; clave aleatoria en SecureStore) con su `client_uuid`. La pantalla dice «Guardado sin internet — se enviará al conectar»; nunca muestra la palomita de confirmado sin respuesta del servidor.
4. Sincroniza con `POST /api/v1/sync/punches` (hasta 100 por lote) al recuperar la red, al abrir o volver a la app, al vencer el reintento exponencial (15 s → 30 min con variación aleatoria) y, de forma oportunista, con `expo-background-task`. Declara `synced_at` (hora de envío según el reloj del teléfono).
5. El servidor procesa cada elemento por separado: `created`, `duplicate` o `rejected` con su `ApiError`. Un rechazo no aborta el lote. Reenviar el mismo `client_uuid` devuelve el mismo marcaje (idempotencia).

Código: `Mobile/src/features/offline/{queue,sync,SyncProvider,backgroundSync}.ts`, `Mobile/src/features/attendance/*`. Pruebas: `Mobile/src/__tests__/{queue,offlineAudit,punchFlow}.test.ts`.

## Qué hace el servidor

| Dato | Uso |
|---|---|
| `device_time` | Hora declarada por el teléfono (no certificada). En capturas sin conexión es la hora del evento si no es posterior a la recepción. |
| `received_at` / `server_time` | Hora oficial de recepción en el servidor (UTC; se muestra en America/Mexico_City o la zona del centro de trabajo). |
| `synced_at` | Hora de envío según el teléfono; permite distinguir sincronización tardía de reloj alterado. |

Reglas (`PunchRecorder`, `ClockSkewMonitor`):

- Marcaje sin conexión cuando la política no lo permite ⇒ se acepta como `flagged` con la anomalía `offline_not_allowed`.
- Sincronizado después de `offline_max_hours` ⇒ `flagged` con `late_sync`.
- Diferencia de más de 10 minutos entre la hora del dispositivo y la recepción ⇒ alerta `clock_skew` en Cumplimiento, clasificada como `offline_late_sync` (captura sin conexión con reloj coherente) o `device_clock_altered` (posible manipulación del reloj). Exactamente 10 minutos no alerta.
- Firma inválida ⇒ `401 invalid_signature`; nunca se acepta.
- En el Panel, «Marcajes sin conexión» cuenta estos marcajes; en Asistencias su origen es «Sin conexión».

Pruebas: `Web/tests/Feature/Api/PunchTest.php` («acepta un marcaje offline sin nonce…», «marca para revisión (flagged) un offline cuando la política no lo permite», «sincroniza lotes con resultados mixtos…»), `Web/tests/Feature/Api/ClockSkewTest.php`.

## Kiosco de sucursal sin conexión

- El kiosco (tableta fija en la sucursal) guarda los marcajes en su propia cola persistente con `client_uuid`, en orden de captura.
- El NIP se conserva sólo mientras el elemento está pendiente y se borra al recibir respuesta.
- Sincroniza con `POST /api/v1/kiosk/sync`, que procesa cada elemento por separado. Sin conexión no hay verificación facial: si la política la exige, el marcaje se rechaza al sincronizar con `face_verification_required`.
- Un kiosco revocado deja de operar y no penaliza la cola.

Código: `Mobile/src/features/kiosk/{queue,sync,punch,sqliteStore}.ts`. Pruebas: `Mobile/src/__tests__/kiosk.test.ts`, `Web/tests/Feature/Api/KioskTest.php` («sincroniza la cola offline procesando cada elemento por separado»).

## Lo que no se hace

- No se generan marcajes para «completar» una jornada (no hay salida automática).
- No se corrige la hora de un marcaje: cualquier corrección es una rectificación con evidencia y firma del colaborador.
- No se borran de la cola local elementos pendientes; sólo se purgan los ya confirmados por el servidor.
