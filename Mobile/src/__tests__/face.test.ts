import {
  buildVerifyParts,
  capturePlan,
  normalizeTimestamps,
  pickPictureSize,
  plannedShots,
  validateFrames,
  type CapturedFrame,
} from '@/features/face/faceVerify';
import type { FaceChallenge } from '@/types/contracts';

const challenge: FaceChallenge = {
  id: 'ch-1',
  steps: [
    { code: 'turn_left', label: 'Gira a tu izquierda' },
    { code: 'move_closer', label: 'Acércate' },
  ],
  expires_at: '2026-11-17T15:00:30Z',
};

const frames = (ts: number[]): CapturedFrame[] => ts.map((t, i) => ({ uri: `file:///f${i}.jpg`, t, bytes: 200_000 }));

describe('verificación facial: plan y validación', () => {
  it('primero de frente y luego cada paso en orden, 6–10 cuadros', () => {
    const plan = capturePlan(challenge);
    expect(plan.map((p) => p.kind)).toEqual(['front', 'turn_left', 'move_closer']);
    const n = plannedShots(plan);
    expect(n).toBeGreaterThanOrEqual(6);
    expect(n).toBeLessThanOrEqual(10);
  });

  it('valida cantidad, orden, límite de 30 s y tamaño', () => {
    expect(validateFrames(frames([100, 400, 900, 1200, 1500, 1800]))).toBeNull();
    expect(validateFrames(frames([1, 2, 3, 4, 5]))).toBe('too_few');
    expect(validateFrames(frames(Array.from({ length: 11 }, (_, i) => i + 1)))).toBe('too_many');
    expect(validateFrames(frames([1, 2, 2, 4, 5, 6]))).toBe('not_increasing');
    expect(validateFrames(frames([1, 2, 3, 4, 5, 30_001]))).toBe('too_late');
    expect(validateFrames([...frames([1, 2, 3, 4, 5]), { uri: 'x', t: 6, bytes: 2_000_000 }])).toBe('too_big');
  });

  it('normaliza timestamps a enteros estrictamente crecientes', () => {
    expect(normalizeTimestamps(frames([10.4, 10.4, 9, 50])).map((f) => f.t)).toEqual([10, 11, 12, 50]);
  });
});

describe('verificación facial: multipart', () => {
  it('arma challenge_id, frames[], timestamps[] y mirrored en orden', () => {
    const parts = buildVerifyParts('ch-1', frames([100, 450, 900, 1250, 1600, 1950]), false);
    expect(parts[0]).toEqual(['challenge_id', 'ch-1']);
    const fr = parts.filter(([k]) => k === 'frames[]');
    expect(fr).toHaveLength(6);
    expect(fr[0]![1]).toEqual({ uri: 'file:///f0.jpg', name: 'frame-1.jpg', type: 'image/jpeg' });
    expect(parts.filter(([k]) => k === 'timestamps[]').map(([, v]) => v)).toEqual(['100', '450', '900', '1250', '1600', '1950']);
    expect(parts.at(-1)).toEqual(['mirrored', '0']);
    expect(buildVerifyParts('x', frames([1, 2, 3, 4, 5, 6]), true).at(-1)).toEqual(['mirrored', '1']);
  });

  it('elige el tamaño de foto más cercano a 640 px', () => {
    expect(pickPictureSize(['4032x3024', '1920x1080', '640x480', '320x240'])).toBe('640x480');
    expect(pickPictureSize(['4032x3024', '1280x720'])).toBe('1280x720');
    expect(pickPictureSize([])).toBeUndefined();
  });
});
