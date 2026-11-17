import json
import shutil
from dataclasses import replace

import numpy as np
import pytest

from app.config import load_settings
from app.engine import Face, FaceEngine, InvalidImage, ModelIntegrityError, decode_jpeg_b64, sha256_of, template_from_b64, template_to_b64
from tests.conftest import ROOT, jpeg_b64


@pytest.fixture(scope="module")
def engine():
    return FaceEngine(load_settings())


def _fake_face(x=200, y=120, w=200, h=240, yaw_px=0.0):
    # Landmarks geométricos sintéticos (no provienen de una persona).
    re, le = (x + 60, y + 90), (x + 140, y + 90)
    nose = (x + 100 + yaw_px, y + 140)
    rm, lm = (x + 70, y + 190), (x + 130, y + 190)
    return Face(np.array([x, y, w, h, *re, *le, *nose, *rm, *lm, 0.99], dtype=np.float32))


def test_manifest_coincide_con_modelos():
    manifest = json.loads((ROOT / "models" / "manifest.json").read_text("utf-8"))["models"]
    for name, entry in manifest.items():
        if name.endswith(".onnx"):
            assert sha256_of(ROOT / "models" / name) == entry["sha256"], name
            assert entry["license"] in ("MIT", "Apache-2.0")


def test_modelo_alterado_impide_arrancar(tmp_path):
    for f in (ROOT / "models").iterdir():
        shutil.copy(f, tmp_path / f.name)
    with (tmp_path / "face_detection_yunet_2023mar.onnx").open("ab") as fh:
        fh.write(b"x")
    with pytest.raises(ModelIntegrityError):
        FaceEngine(replace(load_settings(), models_dir=tmp_path))


def test_detector_no_ve_rostros_en_ruido(engine):
    rng = np.random.default_rng(3)
    assert engine.detect(rng.integers(0, 255, (480, 640, 3), dtype=np.uint8)) == []


def test_embedding_normalizado_y_coseno(engine):
    rng = np.random.default_rng(5)
    img = rng.integers(0, 255, (480, 640, 3), dtype=np.uint8)
    f = engine.embed(img, _fake_face())
    assert f.shape == (1, 128)
    assert abs(float(np.linalg.norm(f)) - 1.0) < 1e-4
    assert engine.similarity(f, f) == pytest.approx(1.0, abs=1e-4)


def test_antispoof_devuelve_probabilidad(engine):
    img = np.full((480, 640, 3), 128, dtype=np.uint8)
    p = engine.real_probability(img, _fake_face())
    assert 0.0 <= p <= 1.0


def test_calidad_detecta_problemas(engine):
    dark = np.full((480, 640, 3), 5, dtype=np.uint8)
    q = engine.quality(dark, _fake_face(w=80, yaw_px=40))
    assert not q["ok"]
    assert {"face_too_small", "too_dark", "blurry", "not_frontal"} <= set(q["reasons"])


def test_plantilla_ida_y_vuelta():
    v = np.arange(128, dtype=np.float32) + 1
    v /= np.linalg.norm(v)
    assert np.allclose(template_from_b64(template_to_b64(v)), v)
    with pytest.raises(InvalidImage):
        template_from_b64(template_to_b64(np.zeros(128, np.float32)))


def test_decodifica_y_reduce(engine):
    out = decode_jpeg_b64(jpeg_b64(np.zeros((2000, 3000, 3), dtype=np.uint8)), engine.settings)
    assert max(out.shape[:2]) == engine.settings.max_side_px


def test_rechaza_cuadro_enorme(engine):
    s = replace(engine.settings, max_frame_bytes=100)
    with pytest.raises(InvalidImage):
        decode_jpeg_b64(jpeg_b64(np.zeros((200, 200, 3), np.uint8)), s)
