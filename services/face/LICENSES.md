# Licencias de modelos y dependencias — services/face

Todos los componentes permiten uso comercial gratuito. Las sumas sha256 de cada
modelo están en `models/manifest.json`; el servicio se niega a arrancar si un
archivo no coincide.

## Modelos

| Modelo | Uso | Origen oficial | Licencia | sha256 |
|---|---|---|---|---|
| YuNet `face_detection_yunet_2023mar.onnx` | Detección + 5 landmarks | [opencv/opencv_zoo](https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet) (también `huggingface.co/opencv/face_detection_yunet`) | MIT (© 2020 Shiqi Yu) | `8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4` |
| SFace `face_recognition_sface_2021dec.onnx` | Plantilla 128-d y comparación coseno | [opencv/opencv_zoo](https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface) | Apache-2.0 | `0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79` |
| MiniFASNetV2 `2.7_80x80_MiniFASNetV2.pth` | Anti-spoofing pasivo (fuente) | [minivision-ai/Silent-Face-Anti-Spoofing](https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/tree/master/resources/anti_spoof_models) | Apache-2.0 (© 2020 Minivision) | `a5eb02e1843f19b5386b953cc4c9f011c3f985d0ee2bb9819eea9a142099bec0` |
| MiniFASNetV1SE `4_0_0_80x80_MiniFASNetV1SE.pth` | Anti-spoofing pasivo (fuente) | ídem | Apache-2.0 | `84ee1d37d96894d5e82de5a57df044ef80a58be2b218b5ed7cdfd875ec2f5990` |
| `minifasnet_v2_2.7_80x80.onnx` | Derivado ONNX (tools/export_onnx.py) | conversión local de los pesos oficiales, sin reentrenar | Apache-2.0 (obra derivada) | ver manifest |
| `minifasnet_v1se_4.0_80x80.onnx` | Derivado ONNX | ídem | Apache-2.0 (obra derivada) | ver manifest |

Verificación de origen hecha el 2026-11-17:
- YuNet/SFace: sha256 = `oid` del puntero Git LFS oficial del repo y `X-Linked-ETag` de Hugging Face.
- MiniFASNet: sha1 de blob git coincide con la API de GitHub del repo oficial.

Obligaciones Apache-2.0 que se cumplen: se conserva la licencia
(`tools/vendor/LICENSE-Silent-Face-Anti-Spoofing`), se atribuye el origen y se
indica la modificación (conversión de formato .pth → ONNX). La arquitectura
`tools/vendor/minifasnet_arch.py` es copia sin cambios del archivo oficial
(sólo con encabezado de atribución) y únicamente se usa para exportar.

## Dependencias Python (ejecución)

| Paquete | Licencia |
|---|---|
| fastapi, pydantic, uvicorn | MIT / BSD-3-Clause |
| opencv-python-headless | Apache-2.0 (OpenCV) / MIT (empaquetado) |
| onnxruntime | MIT |
| numpy | BSD-3-Clause |

Sólo exportación (no se despliega): torch (BSD-3-Clause), onnx / onnxscript (Apache-2.0 / MIT).
