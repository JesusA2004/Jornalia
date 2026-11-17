import * as Crypto from 'expo-crypto';
import * as SecureStore from 'expo-secure-store';

/**
 * Almacenamiento seguro (Keystore en Android / Keychain en iOS).
 *
 * - Sesión (token) y device_uuid: sin autenticación de usuario, para poder
 *   sincronizar en segundo plano.
 * - Secreto del dispositivo: en un `keychainService` propio con
 *   `requireAuthentication: true` cuando el equipo lo soporta, de modo que el SO
 *   exige biometría para leerlo. La documentación de expo-secure-store (SDK 58)
 *   indica que esta opción requiere una clave propia que no se comparta con
 *   valores sin autenticación, por eso usa un servicio distinto.
 */

const KEYS = {
  token: 'session.token',
  tokenExpiresAt: 'session.token_expires_at',
  userId: 'session.user_id',
  deviceUuid: 'device.uuid',
} as const;

const SECRET_SERVICE_PROTECTED = 'mrlana.device_secret.biometric';
const SECRET_SERVICE_CREDENTIAL = 'mrlana.device_secret.credential';

/** Cómo quedó resguardado el secreto del dispositivo. */
export type SecretProtection = 'biometric' | 'credential';

export interface StoredSession {
  token: string;
  tokenExpiresAt: string | null;
  userId: number | null;
}

export async function getSession(): Promise<StoredSession | null> {
  const token = await SecureStore.getItemAsync(KEYS.token);
  if (!token) return null;
  const expires = await SecureStore.getItemAsync(KEYS.tokenExpiresAt);
  const user = await SecureStore.getItemAsync(KEYS.userId);
  return { token, tokenExpiresAt: expires, userId: user ? Number(user) : null };
}

export async function saveSession(s: StoredSession): Promise<void> {
  await SecureStore.setItemAsync(KEYS.token, s.token);
  if (s.tokenExpiresAt) await SecureStore.setItemAsync(KEYS.tokenExpiresAt, s.tokenExpiresAt);
  else await SecureStore.deleteItemAsync(KEYS.tokenExpiresAt);
  if (s.userId !== null) await SecureStore.setItemAsync(KEYS.userId, String(s.userId));
}

export async function clearSession(): Promise<void> {
  await SecureStore.deleteItemAsync(KEYS.token);
  await SecureStore.deleteItemAsync(KEYS.tokenExpiresAt);
  // El userId se conserva para localizar el secreto del dispositivo al volver a entrar.
}

/** UUID del dispositivo: se genera una vez y persiste mientras la app esté instalada. */
export async function getOrCreateDeviceUuid(): Promise<string> {
  const existing = await SecureStore.getItemAsync(KEYS.deviceUuid);
  if (existing) return existing;
  const uuid = Crypto.randomUUID();
  await SecureStore.setItemAsync(KEYS.deviceUuid, uuid);
  return uuid;
}

function secretKey(userId: number): string {
  return `device_secret.u${userId}`;
}

function protectionKey(userId: number): string {
  return `device_secret.u${userId}.protection`;
}

/** true si el SO permite guardar valores protegidos con biometría. */
export function canProtectWithBiometrics(): boolean {
  try {
    return SecureStore.canUseBiometricAuthentication();
  } catch {
    return false;
  }
}

/**
 * Guarda el secreto del dispositivo. En Android, escribir con
 * `requireAuthentication` muestra el diálogo biométrico del sistema.
 * Si el equipo no admite biometría fuerte, se guarda cifrado en Keystore y la
 * lectura se condiciona a la credencial del dispositivo (ver deviceAuth).
 */
export async function saveDeviceSecret(userId: number, secretBase64: string): Promise<SecretProtection> {
  await deleteDeviceSecret(userId);
  if (canProtectWithBiometrics()) {
    await SecureStore.setItemAsync(secretKey(userId), secretBase64, {
      keychainService: SECRET_SERVICE_PROTECTED,
      requireAuthentication: true,
      authenticationPrompt: 'Confirma tu identidad para vincular este dispositivo',
      keychainAccessible: SecureStore.WHEN_PASSCODE_SET_THIS_DEVICE_ONLY,
    });
    await SecureStore.setItemAsync(protectionKey(userId), 'biometric');
    return 'biometric';
  }
  await SecureStore.setItemAsync(secretKey(userId), secretBase64, {
    keychainService: SECRET_SERVICE_CREDENTIAL,
    keychainAccessible: SecureStore.WHEN_PASSCODE_SET_THIS_DEVICE_ONLY,
  });
  await SecureStore.setItemAsync(protectionKey(userId), 'credential');
  return 'credential';
}

export async function getSecretProtection(userId: number): Promise<SecretProtection | null> {
  const v = await SecureStore.getItemAsync(protectionKey(userId));
  return v === 'biometric' || v === 'credential' ? v : null;
}

/**
 * Lee el secreto. Con protección biométrica, el SO muestra su diálogo y la
 * promesa se rechaza si el usuario cancela o falla. Devuelve null si no existe
 * o si el SO invalidó la clave (p. ej. se agregó una huella nueva).
 */
export async function readDeviceSecret(userId: number, protection: SecretProtection, prompt: string): Promise<string | null> {
  if (protection === 'biometric') {
    return SecureStore.getItemAsync(secretKey(userId), {
      keychainService: SECRET_SERVICE_PROTECTED,
      requireAuthentication: true,
      authenticationPrompt: prompt,
    });
  }
  return SecureStore.getItemAsync(secretKey(userId), { keychainService: SECRET_SERVICE_CREDENTIAL });
}

export async function deleteDeviceSecret(userId: number): Promise<void> {
  await SecureStore.deleteItemAsync(secretKey(userId), { keychainService: SECRET_SERVICE_PROTECTED }).catch(() => undefined);
  await SecureStore.deleteItemAsync(secretKey(userId), { keychainService: SECRET_SERVICE_CREDENTIAL }).catch(() => undefined);
  await SecureStore.deleteItemAsync(protectionKey(userId)).catch(() => undefined);
}

/**
 * Pasa un secreto guardado con protección "credential" a protección biométrica
 * (cuando el usuario configura biometría después de vincular). Escribe primero
 * la copia protegida y sólo después elimina la anterior, para no perderlo si
 * el usuario cancela.
 */
export async function upgradeSecretToBiometric(userId: number, secretBase64: string): Promise<void> {
  await SecureStore.setItemAsync(secretKey(userId), secretBase64, {
    keychainService: SECRET_SERVICE_PROTECTED,
    requireAuthentication: true,
    authenticationPrompt: 'Confirma tu identidad para proteger la credencial con biometría',
    keychainAccessible: SecureStore.WHEN_PASSCODE_SET_THIS_DEVICE_ONLY,
  });
  await SecureStore.setItemAsync(protectionKey(userId), 'biometric');
  await SecureStore.deleteItemAsync(secretKey(userId), { keychainService: SECRET_SERVICE_CREDENTIAL }).catch(() => undefined);
}

/** Correo del último inicio de sesión (nunca la contraseña), para precargar el login. */
export async function getLastEmail(): Promise<string | null> {
  return SecureStore.getItemAsync('session.last_email').catch(() => null);
}

export async function setLastEmail(email: string): Promise<void> {
  await SecureStore.setItemAsync('session.last_email', email).catch(() => undefined);
}
