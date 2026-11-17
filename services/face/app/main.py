"""Microservicio interno de verificación facial 1:1 (MR LANA Asistencias).

Escucha sólo en 127.0.0.1. Todas las rutas exigen `Authorization: Bearer
<FACE_SERVICE_TOKEN>`. Las imágenes se procesan en memoria y se descartan:
nunca se escriben a disco ni a logs (las respuestas de error no reflejan la
entrada).
"""

from __future__ import annotations

import hmac
import logging
import time
from functools import lru_cache
from typing import Literal

import numpy as np
from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from . import liveness
from .config import Settings, load_settings
from .engine import Face, FaceEngine, InvalidImage, decode_jpeg_b64, template_from_b64, template_to_b64

logging.getLogger("uvicorn.access").disabled = True  # sin rutas/cuerpos en logs
log = logging.getLogger("face")

VERSION = "1.0.0"
PROVIDER = "opencv-open-source"

app = FastAPI(title="face-service", version=VERSION, docs_url=None, redoc_url=None, openapi_url=None)


@lru_cache(maxsize=1)
def settings() -> Settings:
    return load_settings()


@lru_cache(maxsize=1)
def engine() -> FaceEngine:
    return FaceEngine(settings())


def require_token(authorization: str | None = Header(default=None)) -> None:
    expected = settings().token
    given = (authorization or "").removeprefix("Bearer ").strip()
    if not expected or not hmac.compare_digest(given.encode(), expected.encode()):
        raise HTTPException(status_code=401, detail="unauthorized")


@app.exception_handler(RequestValidationError)
async def _validation(_: Request, exc: RequestValidationError) -> JSONResponse:
    # No devolver la entrada (contendría imágenes en base64).
    fields = sorted({".".join(str(p) for p in e.get("loc", [])[1:2]) for e in exc.errors()})
    return JSONResponse(status_code=422, content={"error": "invalid_request", "fields": fields})


@app.exception_handler(InvalidImage)
async def _invalid_image(_: Request, exc: InvalidImage) -> JSONResponse:
    return JSONResponse(status_code=422, content={"error": "invalid_image", "detail": str(exc)})


# ── Esquemas ────────────────────────────────────────────────────────────────

Step = Literal["turn_left", "turn_right", "move_closer", "move_away"]


class EnrollIn(BaseModel):
    frames: list[str] = Field(min_length=3, max_length=10)


class ChallengeIn(BaseModel):
    steps: list[Step] = Field(min_length=1, max_length=3)
    issued_at_ms: int
    expires_at_ms: int


class VerifyIn(BaseModel):
    template: str = Field(min_length=600, max_length=800)
    frames: list[str] = Field(min_length=6, max_length=10)
    timestamps_ms: list[int] = Field(min_length=6, max_length=10)
    challenge: ChallengeIn
    mirrored: bool = False


# ── Rutas ───────────────────────────────────────────────────────────────────


@app.get("/health", dependencies=[Depends(require_token)])
def health() -> dict:
    e = engine()
    s = e.settings
    return {
        "status": "ok",
        "provider": PROVIDER,
        "version": VERSION,
        "models": ["yunet-2023mar", "sface-2021dec", "minifasnet-v2-2.7", "minifasnet-v1se-4.0"],
        "thresholds": {"match": s.match_threshold, "liveness": s.liveness_threshold},
    }


def _single_faces(imgs: list[np.ndarray]) -> tuple[str | None, list[Face]]:
    faces: list[Face] = []
    for img in imgs:
        found = engine().detect(img)
        if not found:
            return "no_face", []
        if len(found) > 1:
            return "multiple_faces", []
        faces.append(found[0])
    return None, faces


@app.post("/enroll", dependencies=[Depends(require_token)])
def enroll(body: EnrollIn) -> JSONResponse:
    s, e = settings(), engine()
    imgs = [decode_jpeg_b64(f, s) for f in body.frames]
    try:
        error, faces = _single_faces(imgs)
        if error:
            return JSONResponse(status_code=422, content={"result": error})

        qualities = [e.quality(img, f, frontal=True) for img, f in zip(imgs, faces)]
        if not all(q["ok"] for q in qualities):
            reasons = sorted({r for q in qualities for r in q["reasons"]})
            return JSONResponse(status_code=422, content={"result": "poor_quality", "reasons": reasons})

        live = [e.real_probability(img, f) for img, f in zip(imgs, faces)]
        if min(live) < s.liveness_threshold:
            return JSONResponse(status_code=422, content={"result": "liveness_failed", "liveness_score": round(min(live), 4)})

        feats = [e.embed(img, f) for img, f in zip(imgs, faces)]
        pair = min(e.similarity(a, b) for i, a in enumerate(feats) for b in feats[i + 1 :])
        if pair < s.enroll_consistency_threshold:
            return JSONResponse(status_code=422, content={"result": "inconsistent", "consistency": round(pair, 4)})

        mean = np.mean(np.vstack(feats), axis=0)
        mean /= max(1e-12, float(np.linalg.norm(mean)))
        return JSONResponse(content={
            "template": template_to_b64(mean),
            "quality": {
                "frames": len(imgs),
                "min_face_px": min(q["face_px"] for q in qualities),
                "min_sharpness": min(q["sharpness"] for q in qualities),
                "brightness": round(float(np.mean([q["brightness"] for q in qualities])), 1),
                "liveness_score": round(min(live), 4),
                "consistency": round(pair, 4),
            },
        })
    finally:
        del imgs


@app.post("/verify", dependencies=[Depends(require_token)])
def verify(body: VerifyIn) -> dict:
    s, e = settings(), engine()
    if len(body.frames) != len(body.timestamps_ms):
        return JSONResponse(status_code=422, content={"error": "invalid_request", "fields": ["timestamps_ms"]})  # type: ignore[return-value]
    template = template_from_b64(body.template)
    now_ms = int(time.time() * 1000)

    def out(result: str, score: float | None = None, live: float | None = None, passed: bool = False, **extra) -> dict:
        return {
            "result": result,
            "score": None if score is None else round(score, 4),
            "liveness_score": None if live is None else round(live, 4),
            "challenge_passed": passed,
            **extra,
        }

    ts_error = liveness.check_timestamps(body.timestamps_ms, body.challenge.issued_at_ms, body.challenge.expires_at_ms, s.challenge_window_ms)
    if ts_error is None and now_ms > body.challenge.expires_at_ms + 5_000:
        ts_error = "challenge_expired"
    if ts_error:
        return out("liveness_failed", reason=ts_error)

    imgs = [decode_jpeg_b64(f, s) for f in body.frames]
    try:
        error, faces = _single_faces(imgs)
        if error:
            return out(error)

        # Cuadros casi frontales: base de la comparación 1:1 y de la calidad.
        frontal = [i for i, f in enumerate(faces) if abs(f.yaw) <= s.frontal_max_yaw * 1.5]
        if not frontal:
            return out("poor_quality", reason="no_frontal_frame")
        qualities = {i: e.quality(imgs[i], faces[i], frontal=False) for i in frontal}
        good = [i for i in frontal if qualities[i]["ok"]]
        if not good:
            return out("poor_quality", reasons=sorted({r for q in qualities.values() for r in q["reasons"]}))

        # Pasiva: TODOS los cuadros deben superar el umbral.
        live = min(e.real_probability(img, f) for img, f in zip(imgs, faces))

        obs = [liveness.Observation(t, f.yaw, f.size) for t, f in zip(body.timestamps_ms, faces)]
        challenge = liveness.evaluate(
            obs, list(body.challenge.steps), mirrored=body.mirrored,
            turn_delta=s.turn_delta, closer_ratio=s.closer_ratio, away_ratio=s.away_ratio,
        )

        feats = [e.embed(img, f) for img, f in zip(imgs, faces)]
        score = max(e.similarity(template, feats[i]) for i in good)
        best = max(good, key=lambda i: e.similarity(template, feats[i]))
        consistency = min(e.similarity(feats[best], f) for f in feats)

        if live < s.liveness_threshold:
            return out("liveness_failed", score, live, challenge.passed, reason="passive")
        if not challenge.passed:
            return out("liveness_failed", score, live, False, reason=challenge.reason)
        if consistency < s.consistency_threshold:
            return out("liveness_failed", score, live, True, reason="identity_changed")
        if score >= s.match_threshold:
            return out("match", score, live, True)
        if score >= s.match_threshold - s.inconclusive_margin:
            return out("inconclusive", score, live, True)
        return out("no_match", score, live, True)
    finally:
        del imgs
