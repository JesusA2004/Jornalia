import type { PunchType } from '@/types/contracts';

/**
 * Máquina de estados de la pantalla compartida del kiosco (lógica pura).
 * inicio → número → NIP → (rostro) → tipo → enviando → confirmación → inicio.
 * Al volver a inicio se descartan número, NIP y verificación de memoria.
 */
export const EMPLOYEE_MAX = 12;
export const PIN_MIN = 4;
export const PIN_MAX = 8;
/** Inactividad en cualquier paso antes de volver a inicio. */
export const IDLE_TIMEOUT_MS = 30_000;
/** Tiempo que permanece la confirmación. */
export const DONE_TIMEOUT_MS = 5_000;

export type KioskOutcome = { kind: 'confirmed'; time: string; firstName: string | null; receipt: string } | { kind: 'queued'; time: string };

export type KioskFlow =
  | { step: 'idle' }
  | { step: 'number'; digits: string }
  | { step: 'pin'; employee: string; digits: string }
  | { step: 'face'; employee: string; pin: string }
  | { step: 'type'; employee: string; pin: string; faceId: string | null }
  | { step: 'sending'; employee: string; pin: string; faceId: string | null; type: PunchType }
  | { step: 'done'; type: PunchType; outcome: KioskOutcome }
  | { step: 'error'; message: string; retry: 'pin' | 'type' | 'idle'; employee: string; faceId: string | null };

export type KioskRetry = 'pin' | 'type' | 'idle' | 'face';

export type KioskAction =
  | { type: 'start' }
  | { type: 'digit'; d: string }
  | { type: 'backspace' }
  | { type: 'next'; requiresFace: boolean }
  | { type: 'back' }
  | { type: 'faceOk'; id: string }
  | { type: 'choose'; punchType: PunchType }
  | { type: 'success'; outcome: KioskOutcome }
  | { type: 'failure'; message: string; retry: KioskRetry }
  | { type: 'retry' }
  | { type: 'reset' };

export const initialKioskFlow: KioskFlow = { step: 'idle' };

export function kioskReducer(s: KioskFlow, a: KioskAction): KioskFlow {
  if (a.type === 'reset') return initialKioskFlow;
  switch (s.step) {
    case 'idle':
      return a.type === 'start' ? { step: 'number', digits: '' } : s;
    case 'number':
      if (a.type === 'digit' && /^\d$/.test(a.d) && s.digits.length < EMPLOYEE_MAX) return { ...s, digits: s.digits + a.d };
      if (a.type === 'backspace') return { ...s, digits: s.digits.slice(0, -1) };
      if (a.type === 'back') return initialKioskFlow;
      if (a.type === 'next' && s.digits.length > 0) return { step: 'pin', employee: s.digits, digits: '' };
      return s;
    case 'pin':
      if (a.type === 'digit' && /^\d$/.test(a.d) && s.digits.length < PIN_MAX) return { ...s, digits: s.digits + a.d };
      if (a.type === 'backspace') return { ...s, digits: s.digits.slice(0, -1) };
      if (a.type === 'back') return { step: 'number', digits: s.employee };
      if (a.type === 'next' && s.digits.length >= PIN_MIN) {
        return a.requiresFace
          ? { step: 'face', employee: s.employee, pin: s.digits }
          : { step: 'type', employee: s.employee, pin: s.digits, faceId: null };
      }
      return s;
    case 'face':
      if (a.type === 'faceOk') return { step: 'type', employee: s.employee, pin: s.pin, faceId: a.id };
      if (a.type === 'back') return initialKioskFlow;
      if (a.type === 'failure') return { step: 'error', message: a.message, retry: a.retry === 'face' ? 'pin' : a.retry, employee: s.employee, faceId: null };
      return s;
    case 'type':
      if (a.type === 'choose') return { step: 'sending', employee: s.employee, pin: s.pin, faceId: s.faceId, type: a.punchType };
      if (a.type === 'back') return initialKioskFlow;
      return s;
    case 'sending':
      if (a.type === 'success') return { step: 'done', type: s.type, outcome: a.outcome };
      // El servidor exige rostro: se verifica con el mismo número y NIP (sólo en memoria) y se elige el tipo de nuevo.
      if (a.type === 'failure' && a.retry === 'face' && !s.faceId) return { step: 'face', employee: s.employee, pin: s.pin };
      if (a.type === 'failure') return { step: 'error', message: a.message, retry: a.retry === 'face' ? 'idle' : a.retry, employee: s.employee, faceId: s.faceId };
      return s;
    case 'error':
      if (a.type === 'retry') {
        // Reintentar NIP conserva sólo el número capturado; nunca el NIP.
        if (s.retry === 'pin') return { step: 'pin', employee: s.employee, digits: '' };
        return { step: 'number', digits: '' };
      }
      return a.type === 'back' ? initialKioskFlow : s;
    case 'done':
      return a.type === 'start' ? { step: 'number', digits: '' } : s;
    default:
      return s;
  }
}

/** Tiempo de regreso automático a inicio para el paso actual (null = no aplica). */
export function autoResetMs(s: KioskFlow): number | null {
  if (s.step === 'idle' || s.step === 'sending' || s.step === 'face') return null;
  if (s.step === 'done') return DONE_TIMEOUT_MS;
  return IDLE_TIMEOUT_MS;
}
