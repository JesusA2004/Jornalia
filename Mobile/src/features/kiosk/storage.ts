import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex, utf8ToBytes } from '@noble/hashes/utils.js';
import * as SecureStore from 'expo-secure-store';

import type { KioskInfo } from './types';

/**
 * Credencial del kiosco en SecureStore, con claves propias (`kiosk.*`)
 * separadas de la sesión de colaborador.
 */
const KEY_TOKEN = 'kiosk.token';
const KEY_INFO = 'kiosk.info';
/** Huella SHA-256 del código de activación: permite salir del modo kiosco sin guardar el código. */
const KEY_EXIT = 'kiosk.exit_hash';

/** Normaliza y resume el código de activación. */
export function activationHash(code: string): string {
  return bytesToHex(sha256(utf8ToBytes(code.trim().toUpperCase())));
}

export async function saveExitHash(code: string): Promise<void> {
  await SecureStore.setItemAsync(KEY_EXIT, activationHash(code));
}

/** true si `code` coincide con el código usado al activar (o si no hay huella guardada). */
export async function verifyExitCode(code: string): Promise<boolean> {
  const stored = await SecureStore.getItemAsync(KEY_EXIT).catch(() => null);
  if (!stored) return true;
  return stored === activationHash(code);
}

export interface KioskSession {
  token: string;
  kiosk: KioskInfo;
}

export async function getKioskSession(): Promise<KioskSession | null> {
  try {
    const token = await SecureStore.getItemAsync(KEY_TOKEN);
    const raw = await SecureStore.getItemAsync(KEY_INFO);
    if (!token || !raw) return null;
    const kiosk = JSON.parse(raw) as KioskInfo;
    return typeof kiosk?.name === 'string' ? { token, kiosk } : null;
  } catch {
    return null;
  }
}

export async function saveKioskSession(s: KioskSession): Promise<void> {
  await SecureStore.setItemAsync(KEY_TOKEN, s.token);
  await SecureStore.setItemAsync(KEY_INFO, JSON.stringify(s.kiosk));
}

export async function updateKioskInfo(kiosk: KioskInfo): Promise<void> {
  await SecureStore.setItemAsync(KEY_INFO, JSON.stringify(kiosk)).catch(() => undefined);
}

export async function clearKioskSession(): Promise<void> {
  await SecureStore.deleteItemAsync(KEY_TOKEN).catch(() => undefined);
  await SecureStore.deleteItemAsync(KEY_INFO).catch(() => undefined);
  await SecureStore.deleteItemAsync(KEY_EXIT).catch(() => undefined);
}
