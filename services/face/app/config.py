"""Configuración por variables de entorno (sin secretos en código)."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def _load_dotenv(path: Path = ROOT / ".env") -> None:
    """Carga KEY=VALUE de services/face/.env sin sobrescribir el entorno."""
    if not path.exists():
        return
    for line in path.read_text("utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            os.environ.setdefault(key.strip(), value.strip().strip('"'))


def _f(name: str, default: float) -> float:
    return float(os.environ.get(name, default))


def _i(name: str, default: int) -> int:
    return int(os.environ.get(name, default))


@dataclass(frozen=True)
class Settings:
    token: str = field(default_factory=lambda: os.environ.get("FACE_SERVICE_TOKEN", ""))
    models_dir: Path = field(default_factory=lambda: Path(os.environ.get("FACE_MODELS_DIR", ROOT / "models")))

    # Reconocimiento (SFace, coseno). Referencia OpenCV: 0.363. Más estricto por defecto.
    match_threshold: float = field(default_factory=lambda: _f("FACE_MATCH_THRESHOLD", 0.45))
    # Por debajo del umbral, dentro de este margen → inconclusive (no match).
    inconclusive_margin: float = field(default_factory=lambda: _f("FACE_INCONCLUSIVE_MARGIN", 0.05))
    # Todos los cuadros de una secuencia deben ser de la misma persona.
    consistency_threshold: float = field(default_factory=lambda: _f("FACE_CONSISTENCY_THRESHOLD", 0.30))
    # Coherencia entre cuadros al enrolar.
    enroll_consistency_threshold: float = field(default_factory=lambda: _f("FACE_ENROLL_CONSISTENCY", 0.50))

    # Anti-spoofing pasivo (MiniFASNet): probabilidad mínima de "real" en CADA cuadro.
    liveness_threshold: float = field(default_factory=lambda: _f("FACE_LIVENESS_THRESHOLD", 0.75))

    # Detección (YuNet).
    detector_score: float = field(default_factory=lambda: _f("FACE_DETECTOR_SCORE", 0.85))
    min_counted_face_px: int = field(default_factory=lambda: _i("FACE_MIN_COUNTED_PX", 40))

    # Calidad.
    min_face_px: int = field(default_factory=lambda: _i("FACE_MIN_FACE_PX", 112))
    min_brightness: float = field(default_factory=lambda: _f("FACE_MIN_BRIGHTNESS", 50))
    max_brightness: float = field(default_factory=lambda: _f("FACE_MAX_BRIGHTNESS", 210))
    min_sharpness: float = field(default_factory=lambda: _f("FACE_MIN_SHARPNESS", 30))
    frontal_max_yaw: float = field(default_factory=lambda: _f("FACE_FRONTAL_MAX_YAW", 0.20))
    frontal_max_roll_deg: float = field(default_factory=lambda: _f("FACE_FRONTAL_MAX_ROLL", 15))

    # Reto activo (geometría de landmarks).
    turn_delta: float = field(default_factory=lambda: _f("FACE_TURN_DELTA", 0.25))
    closer_ratio: float = field(default_factory=lambda: _f("FACE_CLOSER_RATIO", 1.20))
    away_ratio: float = field(default_factory=lambda: _f("FACE_AWAY_RATIO", 0.83))
    challenge_window_ms: int = field(default_factory=lambda: _i("FACE_CHALLENGE_WINDOW_MS", 30_000))

    # Entradas.
    max_frame_bytes: int = field(default_factory=lambda: _i("FACE_MAX_FRAME_BYTES", 1_500_000))
    max_side_px: int = field(default_factory=lambda: _i("FACE_MAX_SIDE_PX", 960))
    enroll_min_frames: int = 3
    verify_min_frames: int = 6
    max_frames: int = 10


def load_settings() -> Settings:
    _load_dotenv()
    return Settings()
