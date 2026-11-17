import base64
import os
import sys
from pathlib import Path

import cv2
import numpy as np
import pytest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
os.environ.setdefault("FACE_SERVICE_TOKEN", "token-de-prueba")

TOKEN = os.environ["FACE_SERVICE_TOKEN"]


def jpeg_b64(img: np.ndarray) -> str:
    ok, buf = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 90])
    assert ok
    return base64.b64encode(buf.tobytes()).decode()


@pytest.fixture(scope="session")
def client():
    from fastapi.testclient import TestClient

    from app.main import app

    return TestClient(app)


@pytest.fixture
def auth():
    return {"Authorization": f"Bearer {TOKEN}"}


@pytest.fixture
def noise_frames():
    """Imágenes sintéticas SIN rostro (ruido y degradados). Nunca fotos reales."""
    rng = np.random.default_rng(7)
    frames = []
    for i in range(6):
        if i % 2:
            img = rng.integers(0, 255, (480, 640, 3), dtype=np.uint8)
        else:
            grad = np.tile(np.linspace(0, 255, 640, dtype=np.uint8), (480, 1))
            img = cv2.merge([grad, np.flipud(grad), np.full_like(grad, 90 + i * 10)])
        frames.append(jpeg_b64(img))
    return frames
