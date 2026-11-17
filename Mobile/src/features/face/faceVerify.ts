import type { FaceChallenge, FaceChallengeStepCode, FaceVerificationResultCode } from '@/types/contracts';

/**
 * Verificación facial 1:1 (motor open source en servidor). Lógica pura:
 * plan de captura, validación de cuadros/timestamps y armado del multipart.
 * La app nunca decide si el rostro coincide: sólo captura y envía.
 */

export const MIN_FRAMES = 6;
export const MAX_FRAMES = 10;
export const MAX_ELAPSED_MS = 30_000;
export const MAX_FRAME_BYTES = 1.5 * 1024 * 1024;

export interface CapturedFrame {
  uri: string;
  /** ms desde que la app recibió el reto (Date.now() - t0). */
  t: number;
  /** Tamaño en bytes si se conoce (para validar ≤ 1.5 MB). */
  bytes?: number | null;
}

/** Fases de la captura: primero de frente y quieto, luego cada paso en orden. */
export interface CapturePhase {
  kind: 'front' | FaceChallengeStepCode;
  label: string;
  /** Espera antes de la primera foto (tiempo para moverse). */
  settleMs: number;
  /** Fotos en esta fase. */
  shots: number;
  /** Separación entre fotos. */
  gapMs: number;
}

export function capturePlan(challenge: FaceChallenge): CapturePhase[] {
  const phases: CapturePhase[] = [{ kind: 'front', label: 'Mira al frente', settleMs: 900, shots: 2, gapMs: 350 }];
  for (const s of challenge.steps) {
    phases.push({ kind: s.code, label: s.label, settleMs: 1300, shots: 3, gapMs: 350 });
  }
  return phases;
}

export function plannedShots(plan: CapturePhase[]): number {
  return Math.min(MAX_FRAMES, plan.reduce((n, p) => n + p.shots, 0));
}

export type FrameProblem = 'too_few' | 'too_many' | 'not_increasing' | 'too_late' | 'too_big';

/** Valida los cuadros contra las reglas del contrato. */
export function validateFrames(frames: CapturedFrame[]): FrameProblem | null {
  if (frames.length < MIN_FRAMES) return 'too_few';
  if (frames.length > MAX_FRAMES) return 'too_many';
  for (let i = 0; i < frames.length; i++) {
    const f = frames[i]!;
    if (!Number.isInteger(f.t) || f.t < 0 || f.t > MAX_ELAPSED_MS) return 'too_late';
    if (i > 0 && f.t <= frames[i - 1]!.t) return 'not_increasing';
    if (f.bytes != null && f.bytes > MAX_FRAME_BYTES) return 'too_big';
  }
  return null;
}

/** Garantiza timestamps enteros estrictamente crecientes (el reloj puede repetir ms). */
export function normalizeTimestamps(frames: CapturedFrame[]): CapturedFrame[] {
  let prev = -1;
  return frames.map((f) => {
    const t = Math.max(Math.round(f.t), prev + 1);
    prev = t;
    return { ...f, t };
  });
}

export type VerifyPart = [name: string, value: string | { uri: string; name: string; type: string }];

/**
 * Partes del multipart de POST /face-verification/verify en orden:
 * challenge_id, frames[] (image/jpeg), timestamps[] (enteros), mirrored ('0'|'1').
 */
export function buildVerifyParts(challengeId: string, frames: CapturedFrame[], mirrored: boolean): VerifyPart[] {
  const parts: VerifyPart[] = [['challenge_id', challengeId]];
  frames.forEach((f, i) => parts.push(['frames[]', { uri: f.uri, name: `frame-${i + 1}.jpg`, type: 'image/jpeg' }]));
  frames.forEach((f) => parts.push(['timestamps[]', String(f.t)]));
  parts.push(['mirrored', mirrored ? '1' : '0']);
  return parts;
}

export function toFormData(parts: VerifyPart[]): FormData {
  const form = new FormData();
  // React Native acepta { uri, name, type } como archivo.
  for (const [k, v] of parts) form.append(k, v as unknown as Blob);
  return form;
}

/** Mensaje de una línea por resultado no coincidente. */
export const RESULT_UI: Record<Exclude<FaceVerificationResultCode, 'match'>, { title: string; icon: 'face' | 'warning' | 'eye' | 'refresh' }> = {
  no_match: { title: 'No coincide con tu registro', icon: 'face' },
  liveness_failed: { title: 'No pudimos confirmar que eres tú en vivo', icon: 'warning' },
  inconclusive: { title: 'No fue concluyente, intenta de nuevo', icon: 'refresh' },
  no_face: { title: 'No detectamos tu rostro', icon: 'face' },
  multiple_faces: { title: 'Hay más de un rostro', icon: 'warning' },
  poor_quality: { title: 'Mejora la luz y limpia la cámara', icon: 'eye' },
  error: { title: 'Ocurrió un error, intenta de nuevo', icon: 'refresh' },
};

/** Elige el tamaño de foto más cercano a ~640 px de ancho (lado mayor). */
export function pickPictureSize(sizes: string[], target = 640): string | undefined {
  let best: { s: string; d: number } | undefined;
  for (const s of sizes) {
    const m = /^(\d+)x(\d+)$/.exec(s);
    if (!m) continue;
    const w = Math.max(Number(m[1]), Number(m[2]));
    const d = Math.abs(w - target) + (w < 480 ? 1000 : 0);
    if (!best || d < best.d) best = { s, d };
  }
  return best?.s;
}
