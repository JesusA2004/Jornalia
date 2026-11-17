# MR LANA Asistencias — app móvil

App nativa (Expo SDK 58 + Expo Router + React Native 0.88, TypeScript estricto) para que el colaborador **marque asistencia** y **comparta su ubicación de trabajo durante el turno**. Toda la lógica laboral vive en el backend (`Web/`, API `/api/v1`); la app sólo presenta, firma y envía.

## Comandos

```bash
npm install
npx expo start --dev-client          # Metro (con development build instalado)
npx expo run:android --device <id>   # compila e instala el development build
npm test                             # Jest (jest-expo)
npx tsc --noEmit                     # tipos
npx expo lint                        # ESLint
npx expo-doctor                      # diagnóstico
```

Teléfono físico por USB: `adb reverse tcp:8081 tcp:8081` (Metro) y `adb reverse tcp:8000 tcp:8000` (API local) con `EXPO_PUBLIC_API_URL=http://localhost:8000/api/v1`. Emulador: `http://10.0.2.2:8000/api/v1` (valor por omisión).

## Variables (`.env.local`, ver `.env.example`)

| Variable | Uso | Omisión |
|---|---|---|
| `EXPO_PUBLIC_API_URL` | URL base de la API v1 | `http://10.0.2.2:8000/api/v1` |
| `APP_VARIANT` | `development` / `preview` / `production` (nombre y sufijo de paquete) | `production` |
| `ANDROID_PACKAGE` | paquete Android / bundle iOS | `mx.mrlana.asistencias` (`.dev`, `.preview`) |
| `EAS_PROJECT_ID` | projectId de EAS para el token push de Expo | — |

## Build (EAS)

`eas.json`: `development` (dev client, APK), `preview` (APK interno), `production` (AAB, `autoIncrement`). Ejecutar `npx eas-cli@latest build --profile <perfil> --platform android`. EAS sube el repositorio desde su raíz, por lo que `../packages/contracts` queda disponible.

**Windows:** el plugin local `plugins/with-cmake-object-path-max.js` fija `CMAKE_OBJECT_PATH_MAX=250` para que ninja 1.10 (CMake 3.22 del SDK) no falle con rutas > 260 caracteres al compilar en `C:\wamp64\www\Jornalia\Mobile`. Metro ignora `android/` e `ios/` (`metro.config.js`).

## Arquitectura

```
src/app/                 rutas (Expo Router)
  login, dispositivo-pendiente, dispositivo-revocado   (Stack.Protected según sesión)
  (app)/(tabs)/          Inicio (marcar + ubicación), Historial, Perfil
  (app)/…                comprobante, rectificacion, incidencia, vacaciones, solicitudes,
                         notificaciones, dispositivos, acuses, sincronizacion, guia,
                         permiso-ubicacion, camara
src/api/                 cliente HTTP (errores tipados, 401/dispositivo), endpoints del contrato
src/crypto/              base64 + firma HMAC-SHA256 (@noble/hashes, puro JS)
src/features/auth/       AuthProvider + authReducer (sesión, rotación de token, device_pending/revoked)
src/features/attendance/ flujo de marcaje, biometría del SO, ubicación del marcaje, jornada de hoy
src/features/offline/    cola persistente de marcajes, sincronización, tarea en segundo plano
src/features/location/   ubicación de trabajo en tiempo real (lógica pura + adaptador nativo único)
src/features/help/       guía de uso (pasos breves por módulo)
src/storage/             SecureStore (token, device_uuid, secreto) y SQLite cifrada (SQLCipher)
src/theme/               tokens de marca (claro/oscuro), Montserrat + Baloo 2
```

### Contrato API

Los tipos vienen de `packages/contracts/src/api.ts` (fuente única). `src/types/contracts.ts` sólo hace `export * from '@contracts/api'`; el alias `@contracts/*` está en `tsconfig.json` y Metro vigila `../packages/contracts` (`watchFolders`). Jest usa `moduleNameMapper` equivalente. No se copian ni redefinen tipos.

## Seguridad del dispositivo y del marcaje

1. **Login** envía `DeviceInfo` (`device_uuid` generado una vez y guardado en SecureStore). El token se guarda en SecureStore; se rota con `/auth/refresh` cuando faltan < 3 días; 401 → vuelve al login; `device_pending` / `device_revoked` → pantallas propias.
2. **Secreto del dispositivo** (`device_secret`, entregado una sola vez): se guarda en SecureStore en un `keychainService` propio con `requireAuthentication: true` cuando `SecureStore.canUseBiometricAuthentication()` lo permite (Android: Keystore con autenticación de usuario; iOS: `biometryCurrentSet`). El SO exige huella/rostro al leerlo. Si el equipo sólo tiene PIN, se guarda cifrado y la lectura se condiciona a `LocalAuthentication.authenticateAsync` (`auth_method = device_credential`); Perfil ofrece «Usar huella» cuando el usuario la configura. Si la biometría cambia, el SO invalida la clave y la app pide re-vincular.
3. **Marcaje**: validar método permitido por `AttendancePolicy` (incl. `require_strong_biometric`) → ubicación según `location_mode` (sin fabricar coordenadas; `mocked` del SO) → leer secreto (diálogo del SO) → `POST /attendance/challenge` → `signature = hex(HMAC_SHA256(base64decode(secret), client_uuid|type|device_time|nonce|device_uuid))` → `POST /attendance/punches`. Nunca se envía un booleano biométrico y nunca se muestra éxito sin respuesta del servidor: la palomita verde aparece sólo con `PunchReceipt`; sin red se muestra «Guardado sin internet — se enviará al conectar».
4. **Offline**: si no hay conexión (o el servidor no responde) y la política lo permite, se firma con `nonce = null`, `captured_offline = true` y se guarda en la cola cifrada con el mismo `client_uuid` (idempotente). Se sincroniza con `POST /sync/punches` al reconectar (NetInfo), al abrir/volver a la app, al vencer el backoff exponencial (15 s → 30 min, con jitter) y oportunistamente con `expo-background-task` (no se depende de él). Resultados `created`/`duplicate` → sincronizado; `rejected` → visible con su motivo.

## Ubicación de trabajo en tiempo real

Mismo modelo que la app de Auditoría MR LANA (D-138/D-139):

- `src/features/location/tracker.ts` es el **único** adaptador nativo: una tarea `expo-location` + `expo-task-manager` con servicio en primer plano y aviso visible «Ubicación de trabajo activa». Android 12+ no deja iniciarlo desde segundo plano: se (re)inicia al abrir la app. La tarea relee la sesión en cada entrega; sin sesión o con otro usuario se detiene y descarta lo no enviado. Cerrar sesión / 401 detiene el GPS; 403 del endpoint marca «no requerida».
- `src/features/location/workLocation.ts` (puro, con pruebas): filtro de cambios significativos (distancia de `settings`, precisión hasta 100 m, latido 60 s / 300 s), descarte de muestras inválidas (coordenadas imposibles, precisión > 500 m, > 23.5 h, > 2 min en el futuro), ventana de jornada (fuera de turno se descarta antes de guardar), cola con uuid por punto, lotes ≤ 50 marcados antes de enviar y borrados al responder.
- Cadencias: primer plano ~15 s / 10 m (`watchPositionAsync` sólo con la app en pantalla), segundo plano 60 s / 50 m (tarea); ambas las puede ajustar el servidor en `LocationSettings`. Con el teléfono quieto en segundo plano Android no entrega muestras (distanceInterval).
- Inicio muestra «Ubicación activa» (punto verde pulsante), «En pausa (fuera de turno)» o «Activa la ubicación» (botón). Permiso: pantalla visual de un paso; luego «todo el tiempo» en la página del SO.
- La base local usa **SQLCipher** (`useSQLCipher`), clave aleatoria en SecureStore; si el binario no trae SQLCipher, la base no se abre (nunca se guarda en claro).

## Limitaciones

- La biometría del SO **no identifica al empleado**: sólo acredita que quien desbloqueó el teléfono conoce su huella/PIN. La identidad se apoya en el dispositivo aprobado + secreto + políticas del servidor.
- Un emulador no acredita biometría real (simula huellas con `adb emu finger touch`); las pruebas válidas son en teléfono físico.
- **Verificación facial**: el servidor responde `available=false` (no hay proveedor certificado). La app no la ofrece ni implementa reconocimiento facial propio; Perfil lo indica.
- **Push**: requiere `EAS_PROJECT_ID`. Sin él la app pide permiso de notificaciones pero no registra token (se documenta el motivo en `features/push/push.ts`).
- Con el teléfono quieto en segundo plano no hay puntos de ubicación (limitación del SO con `distanceInterval`).

## Recursos de marca

Logotipo, ícono, adaptativos y personaje oficiales en `assets/brand/` (del manual de identidad, sin modificaciones). Colores y tipografías: `docs/identidad/GUIA_MARCA_DIGITAL.md` → `src/theme/tokens.ts`. La tipografía corporativa Museo Sans Rounded requiere licencia; se usan las secundarias oficiales OFL (Montserrat, Baloo 2). `assets/brand/notification-icon.png` es la silueta blanca derivada del monocromo oficial (requisito de Android para el ícono de notificación).
