"""Prueba de vida ACTIVA: verifica un reto aleatorio (2 pasos) con la geometría
de los landmarks de YuNet a lo largo de la secuencia de cuadros.

Pasos admitidos:
- turn_left / turn_right: el yaw (nariz vs. centro de ojos) cambia ≥ turn_delta
  respecto del cuadro inicial frontal, en la dirección pedida.
- move_closer / move_away: el tamaño del rostro crece ≥ closer_ratio o baja
  ≤ away_ratio respecto del cuadro donde terminó el paso anterior.

Los pasos deben cumplirse EN ORDEN, en cuadros distintos y posteriores.
"""

from __future__ import annotations

from dataclasses import dataclass

STEPS = ("turn_left", "turn_right", "move_closer", "move_away")


@dataclass(frozen=True)
class Observation:
    t_ms: int
    yaw: float
    size: float


@dataclass(frozen=True)
class ChallengeOutcome:
    passed: bool
    completed: list[str]
    reason: str | None = None


def check_timestamps(ts: list[int], issued_at_ms: int, expires_at_ms: int, window_ms: int) -> str | None:
    """Timestamps estrictamente crecientes, dentro de la vigencia del reto y la ventana."""
    if any(b <= a for a, b in zip(ts, ts[1:])):
        return "timestamps_not_increasing"
    if ts[0] < issued_at_ms - 2_000 or ts[-1] > expires_at_ms:
        return "outside_challenge_window"
    if ts[-1] - ts[0] > window_ms:
        return "sequence_too_long"
    return None


def evaluate(
    obs: list[Observation],
    steps: list[str],
    *,
    mirrored: bool,
    turn_delta: float,
    closer_ratio: float,
    away_ratio: float,
) -> ChallengeOutcome:
    if not obs or not steps:
        return ChallengeOutcome(False, [], "empty")
    if any(s not in STEPS for s in steps):
        return ChallengeOutcome(False, [], "unknown_step")

    base_yaw = obs[0].yaw
    ref_size = obs[0].size
    sign = -1.0 if mirrored else 1.0
    completed: list[str] = []
    i = 1
    for step in steps:
        done = False
        while i < len(obs):
            o = obs[i]
            i += 1
            dyaw = (o.yaw - base_yaw) * sign
            if step == "turn_left" and dyaw >= turn_delta:
                done = True
            elif step == "turn_right" and dyaw <= -turn_delta:
                done = True
            elif step == "move_closer" and o.size >= ref_size * closer_ratio:
                done = True
            elif step == "move_away" and o.size <= ref_size * away_ratio:
                done = True
            if done:
                ref_size = o.size
                break
        if not done:
            return ChallengeOutcome(False, completed, f"step_not_detected:{step}")
        completed.append(step)
    return ChallengeOutcome(True, completed)
