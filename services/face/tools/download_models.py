"""Descarga los modelos oficiales y verifica su sha256.

Uso:  .venv/Scripts/python -I tools/download_models.py   (desde services/face)

- YuNet y SFace: repositorio oficial opencv/opencv_zoo (sha256 = oid del puntero
  Git LFS oficial; coincide con el X-Linked-ETag de huggingface.co/opencv).
- MiniFASNet (.pth): repositorio oficial minivision-ai/Silent-Face-Anti-Spoofing
  (verificado también contra el sha1 de blob de la API de GitHub). Los .pth sólo
  se usan para generar ONNX con tools/export_onnx.py.

Si un hash no coincide, el archivo se descarta y el script termina con error.
"""

from __future__ import annotations

import hashlib
import json
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MODELS = ROOT / "models"
CACHE = ROOT / ".cache" / "minifasnet"

ZOO = "https://github.com/opencv/opencv_zoo/raw/main/models"
FAS = "https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/raw/master/resources/anti_spoof_models"

SOURCES = [
    {
        "file": "face_detection_yunet_2023mar.onnx",
        "dir": MODELS,
        "url": f"{ZOO}/face_detection_yunet/face_detection_yunet_2023mar.onnx",
        "sha256": "8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4",
        "license": "MIT",
        "role": "detector",
    },
    {
        "file": "face_recognition_sface_2021dec.onnx",
        "dir": MODELS,
        "url": f"{ZOO}/face_recognition_sface/face_recognition_sface_2021dec.onnx",
        "sha256": "0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79",
        "license": "Apache-2.0",
        "role": "recognizer",
    },
    {
        "file": "2.7_80x80_MiniFASNetV2.pth",
        "dir": CACHE,
        "url": f"{FAS}/2.7_80x80_MiniFASNetV2.pth",
        "sha256": "a5eb02e1843f19b5386b953cc4c9f011c3f985d0ee2bb9819eea9a142099bec0",
        "license": "Apache-2.0",
        "role": "antispoof-source",
    },
    {
        "file": "4_0_0_80x80_MiniFASNetV1SE.pth",
        "dir": CACHE,
        "url": f"{FAS}/4_0_0_80x80_MiniFASNetV1SE.pth",
        "sha256": "84ee1d37d96894d5e82de5a57df044ef80a58be2b218b5ed7cdfd875ec2f5990",
        "license": "Apache-2.0",
        "role": "antispoof-source",
    },
]


def sha256_of(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> int:
    manifest_path = MODELS / "manifest.json"
    manifest = json.loads(manifest_path.read_text("utf-8")) if manifest_path.exists() else {"models": {}}
    for src in SOURCES:
        target: Path = src["dir"] / src["file"]
        target.parent.mkdir(parents=True, exist_ok=True)
        if not target.exists() or sha256_of(target) != src["sha256"]:
            print(f"Descargando {src['file']} …")
            urllib.request.urlretrieve(src["url"], target)  # noqa: S310 (URL fija oficial)
        digest = sha256_of(target)
        if digest != src["sha256"]:
            target.unlink(missing_ok=True)
            print(f"ERROR: sha256 no coincide para {src['file']}: {digest}", file=sys.stderr)
            return 1
        manifest["models"][src["file"]] = {
            "role": src["role"],
            "source_url": src["url"],
            "license": src["license"],
            "sha256": digest,
            "size": target.stat().st_size,
        }
        print(f"OK {src['file']} {digest}")
    manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", "utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
