# Verificación facial 1:1 — MR LANA Asistencias

Estado: **funcional en servidor y web** (pruebas automatizadas en verde); **prueba
E2E con un rostro real pendiente** en el teléfono del usuario (ver §9).

> **Aviso honesto.** No es una solución certificada ISO/IEC 30107-3 (PAD) ni
> NIST FRVT. Es un factor adicional robusto contra fotos impresas y pantallas
> comunes, **no infalible**: un video de la persona haciendo los movimientos o una
> máscara 3D pueden superarlo. Úsese junto con dispositivo autorizado, firma del
> marcaje y geocerca; nunca como único control.

## 1. Modelos (sólo open source con uso comercial gratuito)

| Función | Modelo | Licencia |
|---|---|---|
| Detección + 5 landmarks | YuNet 2023mar (OpenCV Zoo), `cv2.FaceDetectorYN` | MIT |
| Reconocimiento | SFace 2021dec (OpenCV Zoo), `cv2.FaceRecognizerSF` (alignCrop + feature + coseno) | Apache-2.0 |
| Anti-spoofing pasivo | MiniFASNetV2 (2.7×) + MiniFASNetV1SE (4.0×), minivision-ai/Silent-Face-Anti-Spoofing, convertidos a ONNX | Apache-2.0 |

Detalle, orígenes y sha256: `services/face/LICENSES.md` y `services/face/models/manifest.json`.
El servicio verifica los sha256 al arrancar y no inicia si algún modelo está alterado.

## 2. Arquitectura

```
App (Expo) ──multipart──▶ Laravel /api/v1/face-verification/* ──JSON+Bearer──▶ services/face (FastAPI, 127.0.0.1:8090)
Web RH (getUserMedia) ──multipart──▶ /colaboradores/{id}/rostro ────────────────▶          "
```

- `services/face`: FastAPI + uvicorn, opencv-python-headless, onnxruntime, numpy. Sin torch en ejecución.
  Rutas: `GET /health`, `POST /enroll`, `POST /verify`; todas exigen `Authorization: Bearer FACE_SERVICE_TOKEN`.
  Escucha sólo en loopback. Sin docs/OpenAPI públicos, sin log de acceso; los errores de validación no reflejan la entrada.
- Laravel (`app/Domain/Biometrics`): contrato `FaceVerificationProvider` (available, name, templateVersion,
  challengeSteps, enroll, verify); `OpenSourceFaceProvider` (HTTP con timeout 15 s / conexión 2 s, health en caché 30 s);
  `UnavailableFaceProvider` si falta `FACE_SERVICE_URL`/`FACE_SERVICE_TOKEN` (`FaceProviderFactory`).
  Si el servicio no responde, `available()` = false y la política no puede exigir rostro.

## 3. Umbrales (configurables)

| Variable (`services/face/.env`) | Defecto | Significado |
|---|---|---|
| `FACE_MATCH_THRESHOLD` | **0.45** | Coseno SFace mínimo para `match`. Referencia OpenCV: 0.363 (más estricto aquí para bajar falsos aceptados). |
| `FACE_INCONCLUSIVE_MARGIN` | 0.05 | `[0.40, 0.45)` → `inconclusive` (no permite marcar). |
| `FACE_LIVENESS_THRESHOLD` | 0.75 | P(real) MiniFASNet mínima en **cada** cuadro (promedio de los 2 modelos; además la clase "real" debe ser la dominante). |
| `FACE_CONSISTENCY_THRESHOLD` | 0.30 | Coseno mínimo entre todos los cuadros y el mejor (detecta cambio de persona a mitad del reto). |
| `FACE_ENROLL_CONSISTENCY` | 0.50 | Coseno mínimo entre cuadros del enrolamiento. |
| `FACE_TURN_DELTA` | 0.25 | Cambio de yaw (nariz vs. centro de ojos / distancia interocular) para un giro. |
| `FACE_CLOSER_RATIO` / `FACE_AWAY_RATIO` | 1.20 / 0.83 | Cambio de tamaño del rostro para acercar/alejar. |
| `FACE_MIN_FACE_PX`, `FACE_MIN/MAX_BRIGHTNESS`, `FACE_MIN_SHARPNESS`, `FACE_FRONTAL_MAX_YAW` | 112 px, 50/210, 30, 0.20 | Calidad. |

Los umbrales de vida y calidad son **iniciales**: deben calibrarse con los teléfonos reales
(ver §9). Laravel (`config/biometrics.php`): reto 30 s, verificación vigente 2 min,
bloqueo 5 fallos / 15 min, 6–10 cuadros ≤ 1.5 MB.

## 4. Flujo

**Enrolamiento (web, RH, permiso `employees.update`)** — `/colaboradores/{id}/rostro`:
1. RH valida identificación oficial en persona.
2. El colaborador firma el consentimiento expreso (documento `BIOMETRIA` de Políticas si existe: se guarda su id y sha256).
3. Cámara del navegador → 5 cuadros JPEG → `/enroll`: exactamente un rostro, calidad (tamaño, luz, nitidez, pose frontal),
   anti-spoofing en todos, coherencia entre cuadros → plantilla = media normalizada de los vectores SFace.
4. Se guarda `face_enrollments.template` **cifrado** (cast `encrypted`, APP_KEY). Re-enrolar revoca el anterior. Auditoría `biometrics.face.enrolled`.

**Verificación (app)**:
1. `POST /face-verification/challenge` → 2 pasos aleatorios distintos, **siempre al menos un giro**
   (acercar/alejar una foto impresa es posible; girar la cabeza cambia la geometría nariz/ojos y una foto plana no).
2. La app captura 6–10 cuadros: el primero de frente y quieto, luego los pasos en orden.
3. `POST /face-verification/verify` (multipart). Laravel consume el reto (un solo uso), valida tiempos y llama a `/verify`.
4. El servicio exige: un rostro por cuadro; timestamps crecientes dentro de la ventana del reto (≤ 30 s);
   anti-spoofing ≥ umbral en **todos** los cuadros; pasos cumplidos en orden; misma persona en toda la secuencia;
   coseno contra la plantilla en cuadros frontales de buena calidad.
5. Se registra `face_verifications` (resultado y métricas; nunca imágenes ni plantilla). Si `match`, el `id` es el
   `face_verification_id` del marcaje; `PunchRecorder` lo valida y lo consume si la política lo exige.
6. 5 resultados no-`match` en 15 min → `face_locked` (429), alerta `face_verification_lockout` y auditoría.
   Errores del motor (`error`) no cuentan.

Resultados: `match | no_match | liveness_failed | inconclusive | no_face | multiple_faces | poor_quality | error`.

## 5. Contrato para la app móvil

Tipos exactos en `packages/contracts/src/api.ts` (sección "Verificación facial").

```
GET  /api/v1/face-verification/status     → { data: { available, provider: 'opencv-open-source'|null, enrolled, consent_required: true } }
POST /api/v1/face-verification/challenge  → 201 { data: { id, steps: [{code, label}], expires_at } }
POST /api/v1/face-verification/verify     multipart/form-data:
       challenge_id   = <uuid>
       frames[]       = 6–10 archivos image/jpeg (≤ 1.5 MB c/u; 720p, calidad ~0.8)
       timestamps[]   = ms desde que la app recibió el reto (Date.now() - t0), uno por cuadro, crecientes, ≤ 30000
       mirrored       = '1' si los cuadros están espejados (vista selfie), '0' si no
     → 200 { data: { id, result, expires_at } }
Errores: face_unavailable 503 · face_not_enrolled 409 · face_locked 429 · face_challenge_invalid 422 ·
         face_challenge_expired 422 · validation_failed 422 · rate_limited 429 (20/min)
```

`mirrored` define el sentido: "izquierda" = izquierda **del colaborador**. Si la app envía la foto tal como la
entrega la cámara frontal sin espejar, `mirrored=0`.

## 6. Privacidad (LFPDPPP 2025)

- La plantilla facial es **dato personal sensible**: consentimiento **expreso y por escrito** (art. 8) antes de
  enrolar; RH lo confirma en pantalla y se guarda hash del documento firmado.
- **Alternativa sin biometría del responsable**: quien no consienta marca con la huella/rostro del SO
  (`device_biometric`), que no sale del teléfono. No debe haber consecuencia laboral.
- Imágenes: se procesan en memoria y se descartan. Nunca se escriben en `storage`, BD ni logs. Nota técnica:
  PHP coloca cada archivo multipart en su directorio temporal durante la petición; Laravel lo lee y lo **elimina de
  inmediato** (`unlink`). En el microservicio no se toca disco.
- Plantilla: cifrada en reposo, oculta en serialización, nunca sale por la API ni la web.
- Minimización: `face_verifications.provider_metadata` sólo guarda puntajes, pasos y motivo.
- Encargado: el motor corre en la propia infraestructura; no hay transferencia a terceros.

## 7. Retención y cancelación (sin borrado físico)

- **Revocar** (RH, con motivo): `status = revoked`; la plantilla deja de usarse.
- **Cancelación ARCO** (excepción legal a la regla "nada se borra"): la plantilla se vacía (`template = null`,
  `template_erased_at`), el registro y su bitácora (`biometrics.face.template_erased`) se conservan. Es irreversible.
- **Pendiente de decisión**: `docs/seguridad/PRIVACIDAD_DATOS.md` indica vaciar la plantilla ≤ 30 días tras la baja o
  la revocación. Hoy ese vaciado es **manual** (botón "Cancelar (ARCO)"); no hay tarea automática. Se recomienda que RH
  lo aplique en cada baja/revocación o que se apruebe una tarea programada.

## 8. Cómo correr el servicio

Desarrollo (Windows, desde `services/face`):

```powershell
python -m venv .venv
.venv\Scripts\python -m pip install -r requirements-dev.txt
.venv\Scripts\python -I tools\download_models.py      # descarga y verifica sha256
# Sólo si faltan los ONNX de MiniFASNet (ya versionados):
#   python -m venv .venv-export; .venv-export\Scripts\pip install -r requirements-export.txt --extra-index-url https://download.pytorch.org/whl/cpu
#   .venv-export\Scripts\python -I tools\export_onnx.py
copy .env.example .env    # FACE_SERVICE_TOKEN = el mismo de Web/.env
.venv\Scripts\python -m uvicorn app.main:app --host 127.0.0.1 --port 8090 --workers 1 --no-access-log
.venv\Scripts\python -m pytest -q
```

`Web/.env`: `FACE_SERVICE_URL=http://127.0.0.1:8090` y `FACE_SERVICE_TOKEN=<aleatorio ≥ 40 car.>`.

Producción (Linux, systemd) — `/etc/systemd/system/mrlana-face.service`:

```ini
[Unit]
Description=MR LANA - verificación facial
After=network.target

[Service]
User=mrlana-face
WorkingDirectory=/srv/jornalia/services/face
EnvironmentFile=/srv/jornalia/services/face/.env
ExecStart=/srv/jornalia/services/face/.venv/bin/python -m uvicorn app.main:app --host 127.0.0.1 --port 8090 --workers 2 --no-access-log
Restart=always
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadOnlyPaths=/srv/jornalia/services/face
MemoryMax=1G

[Install]
WantedBy=multi-user.target
```

Alternativa supervisor:

```ini
[program:mrlana-face]
directory=/srv/jornalia/services/face
command=/srv/jornalia/services/face/.venv/bin/python -m uvicorn app.main:app --host 127.0.0.1 --port 8090 --workers 2 --no-access-log
user=mrlana-face
autorestart=true
stdout_logfile=/var/log/mrlana-face.log
```

No exponer el puerto 8090 fuera de loopback (firewall). Rotar el token en ambos `.env` a la vez.
Rendimiento medido en desarrollo (CPU, 1 worker): ~0.6 s por verificación de 6 cuadros.

## 9. Pruebas y limitaciones

Automatizadas:
- `services/face/tests` (pytest): token, validación sin eco de la entrada, imágenes sintéticas sin rostro,
  integridad de modelos, reto activo con secuencias de landmarks sintéticas, espejado, timestamps.
- `Web/tests/Feature/Biometrics` (Pest, servicio simulado con `Http::fake`): estado, reto, verificación,
  un solo uso, expiración, aislamiento, bloqueo + alerta, caída del motor, enrolamiento cifrado con consentimiento,
  revocación, cancelación ARCO, prohibición de borrado físico, uso del id en el marcaje.

Prueba manual realizada (sin persona real): imagen pública de muestra de OpenCV (`samples/data/lena.jpg`, sólo en
carpeta temporal, no en el repo): detección OK, P(real) 0.997, enrolamiento OK; verificación con cuadros estáticos →
`liveness_failed` (reto no cumplido); con zoom digital para acercar/alejar → `match` (por eso cada reto incluye un giro).

**Pendiente**: prueba E2E con un rostro real en el teléfono del usuario (enrolar en la web, verificar desde la app,
intentar con foto impresa y con pantalla) y calibrar `FACE_LIVENESS_THRESHOLD` y calidad con esos datos.

Limitaciones conocidas:
- MiniFASNet se entrenó con su propio detector (RetinaFace); aquí se usa la caja de YuNet. Funciona, pero su
  precisión en estas condiciones no está medida.
- Una imagen digital (no re-fotografiada) puntúa como real; el anti-spoofing sólo ayuda contra fotos/pantallas
  presentadas a la cámara. Ataques con video de la persona o máscaras 3D pueden pasar.
- Los timestamps los reporta el cliente; la garantía temporal real es la vigencia de 30 s del reto en servidor.
- Sesgo demográfico: SFace/MiniFASNet no se auditaron para la población usuaria.
