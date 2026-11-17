# Notificaciones push (Firebase Cloud Messaging)

El backend envía avisos con **FCM HTTP v1** directamente (OAuth2 con cuenta de servicio, sin SDK; mismo enfoque que el proyecto `auditoria`). Sin credenciales, el push queda **deshabilitado** y la bandeja de la app (`GET /api/v1/notifications`) sigue funcionando.

## 1. Firebase: registrar la app Android

1. Entra a <https://console.firebase.google.com> y abre el proyecto existente **`mrlanaauditoria`** (o crea uno nuevo, p. ej. `mrlana-asistencias`).
2. **Configuración del proyecto** (engrane) → pestaña **General** → **Tus apps** → **Agregar app** → **Android**.
3. Nombre del paquete: `mx.mrlana.asistencias` · Sobrenombre: `MR LANA Asistencias` → **Registrar app**.
4. Repite el paso 2–3 con el paquete de desarrollo `mx.mrlana.asistencias.dev` (y `mx.mrlana.asistencias.preview` si usas esa variante de EAS).
5. Descarga **`google-services.json`** (después de registrar todas las variantes, para que el archivo las incluya todas) y guárdalo en `Mobile/google-services.json`. La integra el agente móvil (`android.googleServicesFile` en `app.config.ts`).
   - Este archivo no es secreto, pero no lo publiques fuera del repositorio privado.
6. **Cloud Messaging**: en **Configuración del proyecto → Cloud Messaging** confirma que **Firebase Cloud Messaging API (V1)** está **Habilitada**. Si aparece deshabilitada, usa el menú ⋮ → *Administrar API en Google Cloud Console* → **Habilitar**.

## 2. Backend: cuenta de servicio

1. **Configuración del proyecto → Cuentas de servicio → Firebase Admin SDK → Generar nueva clave privada** → descarga el JSON.
   - Si el proyecto `mrlanaauditoria` ya tiene una cuenta de servicio en uso por `auditoria`, puedes reutilizarla o generar una clave nueva (cada clave se revoca por separado).
2. Copia el JSON **fuera del repositorio y fuera de `public/`**, por ejemplo:
   - Windows (WAMP): `C:\wamp64\secrets\jornalia\fcm-sa.json`
   - Servidor Linux: `/etc/mr-lana/asistencias/fcm-sa.json` (`chmod 640`, propietario `root:www-data`).
3. En `Web/.env`:

   ```dotenv
   FCM_HABILITADO=true
   FCM_PROJECT_ID=mrlanaauditoria        # «ID del proyecto» en Configuración → General
   FCM_CREDENCIALES_RUTA=C:\wamp64\secrets\jornalia\fcm-sa.json
   # Alternativa a la ruta (gestor de secretos): FCM_CREDENCIALES_JSON='{"type":"service_account",...}'
   ```

4. `php artisan config:clear` (o `config:cache` en producción) y reinicia el worker de cola (`php artisan queue:restart`).
5. Verifica: en la web, **Comunicados** ya no muestra «Push sin configurar».

Nunca se registra el token de acceso, la clave privada ni el token del dispositivo. Un token que FCM reporta como `UNREGISTERED` se **desactiva** (`devices.push_token_invalidated_at`), no se borra; vuelve a activarse cuando la app lo registra de nuevo.

## 3. Tareas programadas y cola

Requiere `* * * * * php artisan schedule:run` y un worker (`php artisan queue:work`).

| Comando | Frecuencia | Qué hace |
|---|---|---|
| `asistencias:notificaciones --solo-comunicados` | cada minuto | Envía comunicados programados. |
| `asistencias:notificaciones --solo-recordatorios` | cada 5 min | «Tu hora de entrada ya pasó» y «Recuerda marcar tu salida» (una vez por colaborador, fecha y tipo). |
| `asistencias:aviso-ubicacion` | una vez (manual) | Publica el aviso `UBICACION` en organizaciones existentes. |

Ajustes por organización (web → Comunicados → Automáticos), en `organization_settings`:
`notifications.late_reminder` `{enabled, grace_minutes}` y `notifications.clock_out_reminder` `{enabled, minutes_after}`.

## 4. Contrato para la app

**Registro del token** (al iniciar sesión, al aprobarse el dispositivo y cada vez que `onTokenRefresh` cambie):

```http
POST /api/v1/devices/push-token
{ "device_uuid": "…", "push_token": "<token FCM>", "provider": "fcm" }
→ 204 · 404 dispositivo ajeno · 403 device_revoked · 422 formato
```

**Mensaje FCM**: `notification` = `{title: "MR LANA Asistencias", body: <texto neutro>}`; Android `channel_id = "avisos"` (crear el canal con importancia alta). `data` (todas las claves son texto):

| Clave | Siempre | Valores |
|---|---|---|
| `notification_id` | sí | UUID del aviso en la bandeja (`POST /notifications/{id}/read` al abrirlo). |
| `category` | sí | `message` · `reminder` · `request` · `attendance` · `system` |
| `kind` | sí | `announcement`, `reminder.late_clock_in`, `reminder.clock_out`, `attendance.auto_clock_out`, `request.approved/rejected`, `device.approved/revoked` |
| `priority` | sí | `normal` · `high` |
| `announcement_id` | comunicados | UUID del comunicado |
| `subject`, `subject_id`, `status` | solicitudes | `vacation`/`incidence`/`rectification`/`overtime`, id, estado |
| `date`, `action` | recordatorios | `YYYY-MM-DD`, `clock_in`/`clock_out` (abrir la pantalla de marcaje) |
| `punch_uuid`, `date` | salida automática | |
| `device_uuid`, `status` | dispositivo | |

Texto visible (pantalla de bloqueo) por categoría: «Tienes un comunicado nuevo.», «Tienes un recordatorio de asistencia.», «Una de tus solicitudes cambió de estado.», «Hay una novedad en tu asistencia.», «Tienes un aviso nuevo.». El título y el cuerpo reales sólo viajan a la bandeja.

**Bandeja** `GET /api/v1/notifications` → `AppNotification` con `category`, `priority` y `data` (mismas claves que arriba, sin `notification_id`). Los comunicados retirados desaparecen de la bandeja.

**Aviso de ubicación**: documento de política `UBICACION` (publicado, requiere acuse) en `GET /profile`; el colaborador lo acusa con `POST /acknowledgements/{document}`.

## 5. Compatibilidad

Las apps anteriores que envían `{device_uuid, expo_push_token}` siguen recibiendo 204; ese token queda como proveedor `expo` y no recibe push FCM. La columna `devices.expo_push_token` se conserva.
