import * as Application from 'expo-application';
import * as Device from 'expo-device';
import * as LocalAuthentication from 'expo-local-authentication';
import { Platform } from 'react-native';

import { APP_VERSION } from '@/config/env';
import { getOrCreateDeviceUuid } from '@/storage/secure';
import type { DeviceInfo, Platform as ApiPlatform } from '@/types/contracts';

export async function biometricAvailable(): Promise<boolean> {
  try {
    const [hw, enrolled] = await Promise.all([LocalAuthentication.hasHardwareAsync(), LocalAuthentication.isEnrolledAsync()]);
    return hw && enrolled;
  } catch {
    return false;
  }
}

/**
 * Identificador del teléfono físico (Android: ANDROID_ID por app/firma/usuario;
 * iOS: identifierForVendor). El servidor impide vincular un teléfono a dos personas.
 */
async function hardwareId(): Promise<string | null> {
  try {
    if (Platform.OS === 'android') return Application.getAndroidId() || null;
    if (Platform.OS === 'ios') return await Application.getIosIdForVendorAsync();
  } catch {
    // no disponible
  }
  return null;
}

/** DeviceInfo para POST /auth/login. */
export async function buildDeviceInfo(): Promise<DeviceInfo> {
  const platform: ApiPlatform = Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'web';
  const model = Device.modelName ?? null;
  return {
    device_uuid: await getOrCreateDeviceUuid(),
    hardware_id: await hardwareId(),
    name: Device.deviceName ?? model ?? (platform === 'ios' ? 'iPhone' : 'Android'),
    platform,
    os_version: Device.osVersion ?? null,
    app_version: Application.nativeApplicationVersion ?? APP_VERSION,
    model,
    biometric_available: await biometricAvailable(),
  };
}
