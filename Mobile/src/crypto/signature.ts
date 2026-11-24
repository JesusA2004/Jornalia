import { hmac } from '@noble/hashes/hmac.js';
import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex, utf8ToBytes } from '@noble/hashes/utils.js';

import type { PunchRequest } from '@/types/contracts';

import { base64ToBytes } from './base64';

export type SignableFields = Pick<PunchRequest, 'client_uuid' | 'type' | 'device_time' | 'nonce' | 'device_uuid'>;

/**
 * Cadena canónica definida por el contrato:
 *   [client_uuid, type, device_time, nonce ?? '', device_uuid].join('|')
 */
export function canonicalString(f: SignableFields): string {
  return [f.client_uuid, f.type, f.device_time, f.nonce ?? '', f.device_uuid].join('|');
}

/** signature = hex(HMAC_SHA256(base64decode(device_secret), canonical)) */
export function signPunch(deviceSecretBase64: string, fields: SignableFields): string {
  const key = base64ToBytes(deviceSecretBase64);
  if (key.length === 0) throw new Error('Secreto de dispositivo vacío');
  return bytesToHex(hmac(sha256, key, utf8ToBytes(canonicalString(fields))));
}

/** Propósito de una firma electrónica (mismo vocabulario que `electronic_signatures.purpose`). */
export type ConsentPurpose = 'rectification' | 'registration_agreement' | 'privacy_notice';

/**
 * Cadena canónica de una firma electrónica con biometría del dispositivo
 * (ElectronicSignatureService::biometricCanonical en el servidor):
 *   "sign|{purpose}|{signable_id}|{payload_sha256}|{device_uuid}"
 */
export function consentCanonical(purpose: ConsentPurpose, subjectId: number | string, payloadSha256: string, deviceUuid: string): string {
  return ['sign', purpose, String(subjectId), payloadSha256, deviceUuid].join('|');
}

/** device_signature = hex(HMAC_SHA256(base64decode(device_secret), consentCanonical)) */
export function signConsent(
  deviceSecretBase64: string,
  purpose: ConsentPurpose,
  subjectId: number | string,
  payloadSha256: string,
  deviceUuid: string,
): string {
  const key = base64ToBytes(deviceSecretBase64);
  if (key.length === 0) throw new Error('Secreto de dispositivo vacío');
  return bytesToHex(hmac(sha256, key, utf8ToBytes(consentCanonical(purpose, subjectId, payloadSha256, deviceUuid))));
}
