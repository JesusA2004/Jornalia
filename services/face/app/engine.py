"""Motor facial: YuNet (detección + 5 landmarks), SFace (plantilla 128-d) y
MiniFASNet (anti-spoofing pasivo, ONNX). Todo en memoria; nada se escribe a disco.
"""

from __future__ import annotations

import base64
import binascii
import hashlib
import json
import math
import threading
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np
import onnxruntime as ort

from .config import Settings

DETECTOR = "face_detection_yunet_2023mar.onnx"
RECOGNIZER = "face_recognition_sface_2021dec.onnx"
ANTISPOOF = ("minifasnet_v2_2.7_80x80.onnx", "minifasnet_v1se_4.0_80x80.onnx")


class InvalidImage(ValueError):
    pass


class ModelIntegrityError(RuntimeError):
    pass


@dataclass(frozen=True)
class Face:
    """Fila de YuNet: x, y, w, h, ojo der., ojo izq., nariz, comisura der., comisura izq., score."""

    row: np.ndarray

    @property
    def box(self) -> tuple[float, float, float, float]:
        x, y, w, h = (float(v) for v in self.row[:4])
        return x, y, w, h

    @property
    def score(self) -> float:
        return float(self.row[14])

    def point(self, i: int) -> tuple[float, float]:
        return float(self.row[4 + 2 * i]), float(self.row[5 + 2 * i])

    @property
    def eye_distance(self) -> float:
        (rx, ry), (lx, ly) = self.point(0), self.point(1)
        return max(1e-6, math.hypot(lx - rx, ly - ry))

    @property
    def yaw(self) -> float:
        """Desplazamiento horizontal de la nariz respecto al centro de los ojos,
        normalizado por la distancia interocular. 0 ≈ frontal; >0 la nariz va a
        la derecha de la imagen (persona girando a SU izquierda si la imagen no
        está espejada)."""
        (rx, _), (lx, _), (nx, _) = self.point(0), self.point(1), self.point(2)
        return (nx - (rx + lx) / 2) / self.eye_distance

    @property
    def roll_deg(self) -> float:
        (rx, ry), (lx, ly) = self.point(0), self.point(1)
        return math.degrees(math.atan2(ly - ry, lx - rx))

    @property
    def size(self) -> float:
        _, _, w, h = self.box
        return math.sqrt(max(w, 1.0) * max(h, 1.0))


def sha256_of(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def decode_jpeg_b64(data: str, settings: Settings) -> np.ndarray:
    """Decodifica un JPEG en base64 a BGR. Reduce a `max_side_px`. Sólo memoria."""
    try:
        raw = base64.b64decode(data, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise InvalidImage("base64 inválido") from exc
    if len(raw) > settings.max_frame_bytes:
        raise InvalidImage("cuadro demasiado grande")
    if not raw.startswith(b"\xff\xd8\xff"):
        raise InvalidImage("se espera JPEG")
    img = cv2.imdecode(np.frombuffer(raw, dtype=np.uint8), cv2.IMREAD_COLOR)
    del raw
    if img is None or img.ndim != 3 or min(img.shape[:2]) < 64:
        raise InvalidImage("JPEG ilegible")
    h, w = img.shape[:2]
    scale = settings.max_side_px / max(h, w)
    if scale < 1:
        img = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)
    return img


def template_to_b64(feature: np.ndarray) -> str:
    return base64.b64encode(np.asarray(feature, dtype="<f4").reshape(128).tobytes()).decode("ascii")


def template_from_b64(data: str) -> np.ndarray:
    try:
        raw = base64.b64decode(data, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise InvalidImage("plantilla inválida") from exc
    if len(raw) != 128 * 4:
        raise InvalidImage("plantilla inválida")
    vec = np.frombuffer(raw, dtype="<f4").astype(np.float32)
    if not np.all(np.isfinite(vec)) or float(np.linalg.norm(vec)) == 0.0:
        raise InvalidImage("plantilla inválida")
    return vec.reshape(1, 128)


def _crop_patch(img: np.ndarray, box: tuple[float, float, float, float], scale: float, size: int = 80) -> np.ndarray:
    """Recorte equivalente a CropImage del repo oficial Silent-Face-Anti-Spoofing."""
    src_h, src_w = img.shape[:2]
    x, y, bw, bh = box
    bw, bh = max(bw, 1.0), max(bh, 1.0)
    scale = min((src_h - 1) / bh, (src_w - 1) / bw, scale)
    nw, nh = bw * scale, bh * scale
    cx, cy = bw / 2 + x, bh / 2 + y
    l, t, r, b = cx - nw / 2, cy - nh / 2, cx + nw / 2, cy + nh / 2
    if l < 0:
        r -= l
        l = 0
    if t < 0:
        b -= t
        t = 0
    if r > src_w - 1:
        l -= r - src_w + 1
        r = src_w - 1
    if b > src_h - 1:
        t -= b - src_h + 1
        b = src_h - 1
    patch = img[int(t) : int(b) + 1, int(l) : int(r) + 1]
    return cv2.resize(patch, (size, size))


class FaceEngine:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self._lock = threading.Lock()
        d = settings.models_dir
        self._verify_manifest(d)
        self.detector = cv2.FaceDetectorYN.create(str(d / DETECTOR), "", (320, 320), settings.detector_score, 0.3, 50)
        self.recognizer = cv2.FaceRecognizerSF.create(str(d / RECOGNIZER), "")
        opts = ort.SessionOptions()
        opts.log_severity_level = 3
        manifest = json.loads((d / "manifest.json").read_text("utf-8"))["models"]
        self.antispoof = [
            (ort.InferenceSession(str(d / name), opts, providers=["CPUExecutionProvider"]), float(manifest[name]["scale"]))
            for name in ANTISPOOF
        ]

    @staticmethod
    def _verify_manifest(d: Path) -> None:
        manifest = json.loads((d / "manifest.json").read_text("utf-8"))["models"]
        for name in (DETECTOR, RECOGNIZER, *ANTISPOOF):
            entry = manifest.get(name)
            path = d / name
            if entry is None or not path.exists() or sha256_of(path) != entry["sha256"]:
                raise ModelIntegrityError(f"Modelo ausente o alterado: {name}")

    # ── Primitivas ──────────────────────────────────────────────────────────

    def detect(self, img: np.ndarray) -> list[Face]:
        h, w = img.shape[:2]
        with self._lock:
            self.detector.setInputSize((w, h))
            _, faces = self.detector.detect(img)
        if faces is None:
            return []
        out = [Face(row=r.copy()) for r in faces if r[2] >= self.settings.min_counted_face_px]
        return sorted(out, key=lambda f: f.size, reverse=True)

    def embed(self, img: np.ndarray, face: Face) -> np.ndarray:
        with self._lock:
            aligned = self.recognizer.alignCrop(img, face.row)
            feat = self.recognizer.feature(aligned)
        feat = np.asarray(feat, dtype=np.float32).reshape(1, 128)
        return feat / max(1e-12, float(np.linalg.norm(feat)))

    def similarity(self, a: np.ndarray, b: np.ndarray) -> float:
        with self._lock:
            return float(self.recognizer.match(a.reshape(1, 128), b.reshape(1, 128), cv2.FaceRecognizerSF_FR_COSINE))

    def real_probability(self, img: np.ndarray, face: Face) -> float:
        """Promedio de P(real) de los dos modelos MiniFASNet oficiales."""
        probs = []
        for sess, scale in self.antispoof:
            patch = _crop_patch(img, face.box, scale).astype(np.float32)  # BGR 0–255, sin normalizar
            x = np.transpose(patch, (2, 0, 1))[None, ...]
            logits = sess.run(None, {"input": x})[0][0]
            e = np.exp(logits - logits.max())
            p = e / e.sum()
            probs.append(p)
        mean = np.mean(probs, axis=0)
        # Clase 1 = real (repo oficial). Debe ser además la clase dominante.
        return float(mean[1]) if int(np.argmax(mean)) == 1 else min(float(mean[1]), 0.5)

    def quality(self, img: np.ndarray, face: Face, frontal: bool = True) -> dict:
        s = self.settings
        x, y, w, h = face.box
        H, W = img.shape[:2]
        x0, y0 = max(0, int(x)), max(0, int(y))
        x1, y1 = min(W, int(x + w)), min(H, int(y + h))
        roi = img[y0:y1, x0:x1]
        gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY) if roi.size else np.zeros((1, 1), np.uint8)
        brightness = float(gray.mean())
        sharp = float(cv2.Laplacian(cv2.resize(gray, (160, 160)), cv2.CV_64F).var()) if roi.size else 0.0
        reasons = []
        if w < s.min_face_px:
            reasons.append("face_too_small")
        if brightness < s.min_brightness:
            reasons.append("too_dark")
        if brightness > s.max_brightness:
            reasons.append("too_bright")
        if sharp < s.min_sharpness:
            reasons.append("blurry")
        if frontal and (abs(face.yaw) > s.frontal_max_yaw or abs(face.roll_deg) > s.frontal_max_roll_deg):
            reasons.append("not_frontal")
        return {
            "ok": not reasons,
            "reasons": reasons,
            "face_px": int(w),
            "brightness": round(brightness, 1),
            "sharpness": round(sharp, 1),
            "yaw": round(face.yaw, 3),
            "roll_deg": round(face.roll_deg, 1),
            "detector_score": round(face.score, 3),
        }
