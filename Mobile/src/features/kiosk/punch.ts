import { ApiRequestError, describeError, NetworkError } from '@/api/errors';

import type { KioskOutcome } from './flow';
import type { KioskQueue } from './queue';
import type { KioskPunchReceipt, KioskPunchRequest } from './types';

/** Error del marcaje en kiosco con el paso al que conviene regresar. */
export class KioskPunchError extends Error {
  constructor(
    message: string,
    readonly retry: 'pin' | 'type' | 'idle' | 'face',
    readonly kioskRevoked = false,
  ) {
    super(message);
    this.name = 'KioskPunchError';
  }
}

const CREDENTIAL_CODES = new Set(['kiosk_invalid_credentials', 'validation_failed', 'invalid_credentials', 'invalid_pin', 'not_found', 'forbidden']);

/**
 * El servidor exige verificación facial vigente: código `face_verification_required`
 * (contrato B1). Servidores anteriores respondían `policy_violation` con un mensaje
 * sobre la verificación facial; se acepta por compatibilidad.
 */
export function isFaceRequired(e: unknown): boolean {
  if (!(e instanceof ApiRequestError)) return false;
  if (e.code === 'face_verification_required' || e.legacyCode === 'face_verification_required') return true;
  return e.code === 'policy_violation' && /facial|rostro/i.test(e.message);
}

/** Traduce el error del servidor sin revelar si falló el número o el NIP. */
export function toKioskError(e: unknown): KioskPunchError {
  if (e instanceof ApiRequestError) {
    if (e.code === 'kiosk_unauthenticated' || e.status === 401 || e.status === 410) {
      return new KioskPunchError('Este kiosco ya no está activo.', 'idle', true);
    }
    if (e.code === 'pin_locked' || e.status === 423 || e.status === 429) {
      return new KioskPunchError('Demasiados intentos. Espera 15 minutos o acude con RH.', 'idle');
    }
    if (isFaceRequired(e)) return new KioskPunchError('Falta verificar tu rostro.', 'face');
    // Convenio o aviso de privacidad pendientes: el colaborador regulariza con RH.
    if (e.code === 'kiosk_employee_inactive' || e.code === 'kiosk_wrong_branch' || e.code === 'pin_not_set' || e.code === 'documents_pending') {
      return new KioskPunchError(e.message, 'idle');
    }
    if (e.code === 'invalid_sequence' || e.code === 'duplicate_punch' || e.code === 'policy_violation' || e.code === 'no_employment') {
      return new KioskPunchError(e.message, 'idle');
    }
    if (e.status === 422 || e.status === 404 || e.status === 403 || CREDENTIAL_CODES.has(e.code)) {
      return new KioskPunchError('Número o NIP incorrecto', 'pin');
    }
    return new KioskPunchError(describeError(e), 'idle');
  }
  return new KioskPunchError(describeError(e), 'idle');
}

const unreachable = (e: unknown) => e instanceof NetworkError || (e instanceof ApiRequestError && e.isRetriable);

/**
 * Envía un marcaje de kiosco. Sólo devuelve «confirmed» si el servidor
 * respondió; sin red (y si el kiosco lo permite) queda «queued».
 */
export async function submitKioskPunch(
  request: KioskPunchRequest,
  deps: {
    isOnline: boolean;
    allowOffline: boolean;
    send: (b: KioskPunchRequest) => Promise<{ data: KioskPunchReceipt }>;
    queue: KioskQueue;
  },
): Promise<KioskOutcome> {
  const enqueue = async (): Promise<KioskOutcome> => {
    if (!deps.allowOffline) throw new KioskPunchError('Sin conexión. No se registró el marcaje.', 'idle');
    await deps.queue.enqueue(request);
    return { kind: 'queued', time: request.device_time };
  };
  if (!deps.isOnline) return enqueue();
  try {
    const r = await deps.send({ ...request, captured_offline: false });
    return {
      kind: 'confirmed',
      time: r.data.punch.server_time,
      firstName: r.data.employee?.first_name ?? null,
      receipt: r.data.receipt_code,
    };
  } catch (e) {
    if (unreachable(e)) return enqueue();
    throw toKioskError(e);
  }
}
