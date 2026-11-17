from app.liveness import Observation, check_timestamps, evaluate

K = dict(turn_delta=0.25, closer_ratio=1.2, away_ratio=0.83)


def seq(*pairs):
    return [Observation(i * 200, yaw, size) for i, (yaw, size) in enumerate(pairs)]


def test_izquierda_luego_acercarse():
    obs = seq((0, 150), (0.1, 150), (0.35, 148), (0.05, 160), (0.0, 190), (0, 192))
    out = evaluate(obs, ["turn_left", "move_closer"], mirrored=False, **K)
    assert out.passed and out.completed == ["turn_left", "move_closer"]


def test_orden_importa():
    obs = seq((0, 150), (0, 190), (0.35, 190), (0, 190), (0, 190), (0, 190))
    assert not evaluate(obs, ["turn_left", "move_closer"], mirrored=False, **K).passed


def test_espejado_invierte_direccion():
    obs = seq((0, 150), (0.1, 150), (0.4, 150), (0, 150), (-0.4, 150), (0, 150))
    assert evaluate(obs, ["turn_left", "turn_right"], mirrored=False, **K).passed
    assert evaluate(obs, ["turn_right", "turn_left"], mirrored=True, **K).passed
    assert not evaluate(obs, ["turn_right", "turn_left"], mirrored=False, **K).passed


def test_foto_estatica_no_pasa():
    obs = seq(*[(0.01, 150)] * 8)
    for steps in (["turn_left", "turn_right"], ["move_closer", "move_away"]):
        assert not evaluate(obs, steps, mirrored=False, **K).passed


def test_alejarse_tras_acercarse_usa_referencia_nueva():
    obs = seq((0, 150), (0, 185), (0, 175), (0, 150), (0, 150), (0, 150))
    assert evaluate(obs, ["move_closer", "move_away"], mirrored=False, **K).passed


def test_timestamps():
    assert check_timestamps([1000, 1100, 1200], 1000, 31_000, 30_000) is None
    assert check_timestamps([1000, 1000, 1200], 1000, 31_000, 30_000) == "timestamps_not_increasing"
    assert check_timestamps([1000, 1100, 40_000], 1000, 50_000, 30_000) == "sequence_too_long"
    assert check_timestamps([1000, 1100, 32_000], 1000, 31_000, 30_000) == "outside_challenge_window"
