import * as LocalAuthentication from 'expo-local-authentication';

import { canProtectWithBiometrics, readDeviceSecret, type SecretProtection, upgradeSecretToBiometric } from '@/storage/secure';
import type { AttendancePolicy, AuthMethod } from '@/types/contracts';

import { PunchFlowError } from './errors';

export interface DeviceAuthCapability {
  hasHardware: boolean;
  isEnrolled: boolean;
  level: LocalAuthentication.SecurityLevel;
  /** Biometría clase fuerte (huella / rostro 3D). */
  strong: boolean;
  /** El SO permite guardar valores con requireAuthentication. */
  canBindSecret: boolean;
}

export async function getDeviceAuthCapability(): Promise<DeviceAuthCapability> {
  const [hasHardware, isEnrolled, level] = await Promise.all([
    LocalAuthentication.hasHardwareAsync().catch(() => false),
    LocalAuthentication.isEnrolledAsync().catch(() => false),
    LocalAuthentication.getEnrolledLevelAsync().catch(() => LocalAuthentication.SecurityLevel.NONE),
  ]);
  return {
    hasHardware,
    isEnrolled,
    level,
    strong: level === LocalAuthentication.SecurityLevel.BIOMETRIC_STRONG,
    canBindSecret: canProtectWithBiometrics(),
  };
}

/** Método de autenticación que corresponde a la forma en que se guardó el secreto. */
export function authMethodFor(protection: SecretProtection): AuthMethod {
  return protection === 'biometric' ? 'device_biometric' : 'device_credential';
}

/**
 * Valida que el método disponible en el dispositivo esté permitido por la política.
 * Lanza PunchFlowError con un mensaje accionable si no lo está.
 */
/** Verificación facial previa: el método declarado es 'face_verification' (la firma del dispositivo se sigue exigiendo). */
export function assertFaceAllowed(protection: SecretProtection | null, policy: AttendancePolicy): AuthMethod {
  if (!protection) {
    throw new PunchFlowError('no_secret', 'Este teléfono no puede marcar. Pide a tu administrador que lo vuelva a vincular.');
  }
  if (!policy.allowed_auth_methods.includes('face_verification')) {
    throw new PunchFlowError('method_not_allowed', 'Tu empresa no permite marcar con rostro.');
  }
  return 'face_verification';
}

export function assertMethodAllowed(
  protection: SecretProtection | null,
  policy: AttendancePolicy,
  capability: DeviceAuthCapability,
): AuthMethod {
  if (!protection) {
    throw new PunchFlowError(
      'no_secret',
      'Este dispositivo no tiene la credencial de firma. Pide a tu administrador que lo vuelva a vincular e inicia sesión de nuevo.',
    );
  }
  const method = authMethodFor(protection);
  const allowed = policy.allowed_auth_methods;
  if (!allowed.includes(method)) {
    if (allowed.length === 1 && allowed[0] === 'face_verification') {
      throw new PunchFlowError('face_required', 'Tu empresa requiere marcar con rostro.');
    }
    throw new PunchFlowError(
      'method_not_allowed',
      method === 'device_credential'
        ? 'Tu empresa requiere biometría para marcar. Configura tu huella o rostro en el teléfono y protege la credencial desde Perfil › Dispositivo.'
        : 'La política de tu empresa no permite marcar con biometría desde este dispositivo.',
    );
  }
  if (method === 'device_biometric') {
    if (!capability.isEnrolled) {
      throw new PunchFlowError('auth_unavailable', 'No hay biometría configurada en el teléfono. Configúrala para poder marcar.');
    }
    if (policy.require_strong_biometric && !capability.strong) {
      throw new PunchFlowError(
        'weak_biometric',
        'Tu empresa requiere biometría de seguridad alta (huella o reconocimiento facial 3D). La biometría configurada no cumple ese nivel.',
      );
    }
  } else if (capability.level === LocalAuthentication.SecurityLevel.NONE) {
    throw new PunchFlowError('auth_unavailable', 'Configura un bloqueo de pantalla (PIN, patrón o biometría) para poder marcar.');
  }
  return method;
}

function isCancel(message: string): boolean {
  return /cancel/i.test(message);
}

/**
 * Desbloquea el secreto del dispositivo.
 *  - biometric: el propio SO pide biometría al leer el valor (requireAuthentication).
 *  - credential: se exige autenticación del dispositivo (biometría o PIN) antes de leer.
 * Nunca se envía un booleano de "biometría OK" al servidor: lo que viaja es la firma.
 */
export async function unlockDeviceSecret(userId: number, protection: SecretProtection, prompt: string): Promise<string> {
  let secret: string | null;
  if (protection === 'credential') {
    const r = await LocalAuthentication.authenticateAsync({ promptMessage: prompt, cancelLabel: 'Cancelar' });
    if (!r.success) {
      if (r.error === 'user_cancel' || r.error === 'system_cancel' || r.error === 'app_cancel') {
        throw new PunchFlowError('auth_cancelled', 'Cancelaste la verificación. El marcaje no se registró.');
      }
      if (r.error === 'passcode_not_set' || r.error === 'not_enrolled' || r.error === 'not_available') {
        throw new PunchFlowError('auth_unavailable', 'Configura un bloqueo de pantalla para poder marcar.');
      }
      if (r.error === 'lockout') {
        throw new PunchFlowError('auth_failed', 'Demasiados intentos fallidos. Desbloquea el teléfono e intenta de nuevo.');
      }
      throw new PunchFlowError('auth_failed', 'No se pudo verificar tu identidad. El marcaje no se registró.');
    }
  }
  try {
    secret = await readDeviceSecret(userId, protection, prompt);
  } catch (e) {
    const msg = e instanceof Error ? e.message : '';
    if (isCancel(msg)) throw new PunchFlowError('auth_cancelled', 'Cancelaste la verificación. El marcaje no se registró.');
    throw new PunchFlowError('auth_failed', 'No se pudo verificar tu identidad. El marcaje no se registró.');
  }
  if (!secret) {
    throw new PunchFlowError(
      'secret_invalidated',
      'La credencial de firma ya no es válida en este teléfono (por ejemplo, cambió la biometría registrada). Pide a tu administrador que vuelva a vincular el dispositivo.',
    );
  }
  return secret;
}

/** Re-protege con biometría un secreto guardado con credencial del dispositivo. */
export async function upgradeProtectionToBiometric(userId: number): Promise<void> {
  if (!canProtectWithBiometrics()) {
    throw new PunchFlowError('auth_unavailable', 'Configura una huella o rostro en los ajustes del teléfono y vuelve a intentarlo.');
  }
  const secret = await unlockDeviceSecret(userId, 'credential', 'Confirma tu identidad para proteger la credencial');
  await upgradeSecretToBiometric(userId, secret);
}
