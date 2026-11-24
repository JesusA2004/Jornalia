/**
 * NIP de firma electrónica. El servidor acepta 4 a 6 dígitos (también lo usa
 * el kiosco); la app pide 6 al crear uno nuevo. El servidor valida de nuevo y
 * bloquea 15 min tras 5 intentos fallidos.
 */
export const PIN_LENGTH = 6;
export const PIN_MIN = 4;
export const PIN_MAX = 6;

export type PinProblem = 'length' | 'digits' | 'repeated' | 'sequence' | 'mismatch';

/** Mensaje corto por problema. */
export const PIN_PROBLEM_LABEL: Record<PinProblem, string> = {
  length: `Usa ${PIN_LENGTH} dígitos.`,
  digits: 'Sólo números.',
  repeated: 'No repitas el mismo número.',
  sequence: 'Evita secuencias como 123456.',
  mismatch: 'Los NIP no coinciden.',
};

/** Valida un NIP nuevo. Devuelve el primer problema o null. */
export function validateNewPin(pin: string, confirm?: string): PinProblem | null {
  if (!/^\d*$/.test(pin)) return 'digits';
  if (pin.length !== PIN_LENGTH) return 'length';
  if (/^(\d)\1+$/.test(pin)) return 'repeated';
  const digits = pin.split('').map(Number);
  const asc = digits.every((d, i) => i === 0 || d === (digits[i - 1]! + 1) % 10);
  const desc = digits.every((d, i) => i === 0 || d === (digits[i - 1]! + 9) % 10);
  if (asc || desc) return 'sequence';
  if (confirm !== undefined && confirm !== pin) return 'mismatch';
  return null;
}

/** Normaliza lo tecleado: sólo dígitos, máximo PIN_MAX. */
export function sanitizePinInput(text: string): string {
  return text.replace(/\D+/g, '').slice(0, PIN_MAX);
}

/** ¿Se puede intentar firmar con este NIP? (4 a 6 dígitos). */
export function isPinComplete(pin: string): boolean {
  return pin.length >= PIN_MIN && pin.length <= PIN_MAX && /^\d+$/.test(pin);
}
