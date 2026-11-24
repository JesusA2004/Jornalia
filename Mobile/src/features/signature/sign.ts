import { type ConsentPurpose, signConsent } from '@/crypto/signature';
import type { SignatureMethod, SignRequest } from '@/types/contracts';
import { isoNow } from '@/utils/time';

import { isPinComplete } from './pin';

/**
 * Firma electrónica del colaborador (rectificaciones, convenio y aviso).
 *
 * - NIP: viaja sólo en la petición HTTPS; el servidor lo verifica contra su
 *   hash y lo bloquea 15 min tras 5 intentos fallidos.
 * - Biometría: el SO desbloquea el secreto del dispositivo (huella/rostro) y la
 *   app envía una firma HMAC sobre el SHA-256 del contenido que entrega el
 *   servidor. Nunca se envía un booleano «biometría OK».
 * El servidor sella la firma (fecha y hora, dispositivo, IP, cadena de hashes).
 */
export interface SignInput {
  purpose: ConsentPurpose;
  subjectId: number | string;
  method: SignatureMethod;
  pin?: string | null;
  /** `signature_payload_sha256` del servidor (obligatorio con biometría). */
  payloadSha256?: string | null;
}

export interface SignDeps {
  deviceUuid: () => Promise<string>;
  /** Desbloquea el secreto con biometría/credencial del SO (sólo method='biometric'). */
  unlockSecret: () => Promise<string>;
  now?: () => Date;
}

export class SignatureInputError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'SignatureInputError';
  }
}

/** ¿Se puede firmar con biometría? (requiere el hash del contenido). */
export function canSignWithDevice(payloadSha256: string | null | undefined): boolean {
  return typeof payloadSha256 === 'string' && /^[a-f0-9]{64}$/.test(payloadSha256);
}

/** Arma el cuerpo de POST …/sign. Lanza si falta el NIP o el usuario cancela la biometría. */
export async function buildSignRequest(input: SignInput, deps: SignDeps): Promise<SignRequest> {
  const deviceUuid = await deps.deviceUuid();
  const deviceTime = isoNow((deps.now ?? (() => new Date()))());
  if (input.method === 'pin') {
    const pin = input.pin ?? '';
    if (!isPinComplete(pin)) throw new SignatureInputError('Escribe tu NIP.');
    return { method: 'pin', pin, device_uuid: deviceUuid, device_time: deviceTime };
  }
  if (!canSignWithDevice(input.payloadSha256)) throw new SignatureInputError('Firma con huella no disponible. Usa tu NIP.');
  const secret = await deps.unlockSecret();
  return {
    method: 'biometric',
    device_uuid: deviceUuid,
    device_time: deviceTime,
    device_signature: signConsent(secret, input.purpose, input.subjectId, input.payloadSha256!, deviceUuid),
  };
}
