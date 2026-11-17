"""Conversión ÚNICA de los pesos oficiales MiniFASNet (.pth) a ONNX.

torch sólo se usa aquí, en un venv aparte (.venv-export, requirements-export.txt).
El microservicio en ejecución sólo necesita onnxruntime.

Uso (desde services/face):
    .venv/Scripts/python -I tools/download_models.py
    .venv-export/Scripts/python -I tools/export_onnx.py

Preprocesamiento esperado por los modelos (igual que el repo oficial):
recorte cuadrado alrededor del rostro con escala 2.7 / 4.0, 80x80, BGR,
valores 0–255 sin normalizar (float32), NCHW. Salida: logits de 3 clases;
softmax[1] = rostro real.
"""

from __future__ import annotations

import hashlib
import json
import sys
from collections import OrderedDict
from pathlib import Path

import numpy as np
import torch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from tools.vendor.minifasnet_arch import MiniFASNetV1SE, MiniFASNetV2  # noqa: E402

MODELS = ROOT / "models"
CACHE = ROOT / ".cache" / "minifasnet"

TARGETS = [
    ("2.7_80x80_MiniFASNetV2.pth", MiniFASNetV2, 2.7, "minifasnet_v2_2.7_80x80.onnx"),
    ("4_0_0_80x80_MiniFASNetV1SE.pth", MiniFASNetV1SE, 4.0, "minifasnet_v1se_4.0_80x80.onnx"),
]


def sha256_of(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    manifest_path = MODELS / "manifest.json"
    manifest = json.loads(manifest_path.read_text("utf-8"))
    for pth_name, factory, scale, onnx_name in TARGETS:
        pth = CACHE / pth_name
        expected = manifest["models"][pth_name]["sha256"]
        if sha256_of(pth) != expected:
            print(f"ERROR: {pth_name} no coincide con el manifest", file=sys.stderr)
            return 1

        model = factory(conv6_kernel=((80 + 15) // 16, (80 + 15) // 16))
        state = torch.load(pth, map_location="cpu", weights_only=True)
        state = OrderedDict((k[7:] if k.startswith("module.") else k, v) for k, v in state.items())
        model.load_state_dict(state)
        model.eval()

        dummy = torch.rand(1, 3, 80, 80) * 255.0
        out = MODELS / onnx_name
        torch.onnx.export(
            model, dummy, str(out), input_names=["input"], output_names=["logits"],
            dynamic_axes={"input": {0: "batch"}, "logits": {0: "batch"}}, opset_version=13, dynamo=False,
        )

        import onnxruntime as ort

        sess = ort.InferenceSession(str(out), providers=["CPUExecutionProvider"])
        with torch.no_grad():
            ref = model(dummy).numpy()
        got = sess.run(None, {"input": dummy.numpy()})[0]
        diff = float(np.abs(ref - got).max())
        if diff > 1e-3:
            print(f"ERROR: ONNX difiere de torch ({diff})", file=sys.stderr)
            return 1

        manifest["models"][onnx_name] = {
            "role": "antispoof",
            "derived_from": pth_name,
            "source_url": manifest["models"][pth_name]["source_url"],
            "license": "Apache-2.0",
            "scale": scale,
            "input": [80, 80],
            "sha256": sha256_of(out),
            "size": out.stat().st_size,
            "max_abs_diff_vs_torch": diff,
        }
        print(f"OK {onnx_name} diff={diff:.2e}")

    manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", "utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
