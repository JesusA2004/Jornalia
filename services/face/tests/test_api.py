import base64
import time

import numpy as np

from app.engine import template_to_b64


def _template():
    v = np.random.default_rng(1).normal(size=128).astype(np.float32)
    return template_to_b64(v / np.linalg.norm(v))


def _challenge(steps=("turn_left", "move_closer")):
    now = int(time.time() * 1000)
    return {"steps": list(steps), "issued_at_ms": now - 1000, "expires_at_ms": now + 29_000}


def _ts(n=6):
    now = int(time.time() * 1000)
    return [now - 900 + i * 150 for i in range(n)]


def test_health_requiere_token(client):
    assert client.get("/health").status_code == 401
    assert client.get("/health", headers={"Authorization": "Bearer otro"}).status_code == 401


def test_health_ok(client, auth):
    r = client.get("/health", headers=auth)
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "ok" and body["provider"] == "opencv-open-source"
    assert body["thresholds"]["match"] >= 0.363


def test_enroll_sin_token(client, noise_frames):
    assert client.post("/enroll", json={"frames": noise_frames[:3]}).status_code == 401


def test_enroll_sin_rostro(client, auth, noise_frames):
    r = client.post("/enroll", json={"frames": noise_frames[:3]}, headers=auth)
    assert r.status_code == 422
    assert r.json() == {"result": "no_face"}


def test_enroll_rechaza_no_jpeg_sin_reflejar_entrada(client, auth):
    png = base64.b64encode(b"\x89PNG\r\n\x1a\n" + b"0" * 100).decode()
    r = client.post("/enroll", json={"frames": [png] * 3}, headers=auth)
    assert r.status_code == 422
    assert r.json()["error"] == "invalid_image"
    assert png not in r.text


def test_enroll_valida_numero_de_cuadros(client, auth, noise_frames):
    r = client.post("/enroll", json={"frames": noise_frames[:2]}, headers=auth)
    assert r.status_code == 422
    assert r.json() == {"error": "invalid_request", "fields": ["frames"]}
    assert noise_frames[0] not in r.text


def test_verify_sin_rostro(client, auth, noise_frames):
    body = {"template": _template(), "frames": noise_frames, "timestamps_ms": _ts(), "challenge": _challenge()}
    r = client.post("/verify", json=body, headers=auth)
    assert r.status_code == 200
    assert r.json()["result"] == "no_face"
    assert r.json()["challenge_passed"] is False


def test_verify_timestamps_no_crecientes(client, auth, noise_frames):
    ts = _ts()
    ts[3] = ts[2]
    body = {"template": _template(), "frames": noise_frames, "timestamps_ms": ts, "challenge": _challenge()}
    r = client.post("/verify", json=body, headers=auth).json()
    assert r["result"] == "liveness_failed" and r["reason"] == "timestamps_not_increasing"


def test_verify_fuera_de_ventana(client, auth, noise_frames):
    ch = _challenge()
    ch["expires_at_ms"] = int(time.time() * 1000) - 60_000
    ch["issued_at_ms"] = ch["expires_at_ms"] - 30_000
    body = {"template": _template(), "frames": noise_frames, "timestamps_ms": _ts(), "challenge": ch}
    r = client.post("/verify", json=body, headers=auth).json()
    assert r["result"] == "liveness_failed" and r["reason"] == "outside_challenge_window"


def test_verify_plantilla_invalida(client, auth, noise_frames):
    body = {"template": "A" * 684, "frames": noise_frames, "timestamps_ms": _ts(), "challenge": _challenge()}
    assert client.post("/verify", json=body, headers=auth).status_code == 422


def test_verify_paso_desconocido(client, auth, noise_frames):
    body = {"template": _template(), "frames": noise_frames, "timestamps_ms": _ts(), "challenge": _challenge(("blink",))}
    assert client.post("/verify", json=body, headers=auth).status_code == 422
