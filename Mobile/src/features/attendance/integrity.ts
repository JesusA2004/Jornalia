import * as Device from 'expo-device';
import { Platform } from 'react-native';

import type { DeviceIntegritySignal, LocationEvidence } from '@/types/contracts';

/**
 * Señales de integridad del teléfono (contrato `DeviceIntegritySignal`):
 * root/jailbreak (heurística experimental del SO), emulador y ubicación
 * simulada. No bloquean el marcaje: el servidor genera alerta en Cumplimiento.
 * La detección no es infalible.
 */
let cached: DeviceIntegritySignal[] | null = null;

export async function deviceIntegrity(): Promise<DeviceIntegritySignal[]> {
  if (cached) return cached;
  const out: DeviceIntegritySignal[] = [];
  if (Platform.OS !== 'web') {
    try {
      if (await Device.isRootedExperimentalAsync()) out.push(Platform.OS === 'ios' ? 'jailbroken' : 'rooted');
    } catch {
      // sin dato
    }
    if (!Device.isDevice) out.push('emulator');
  }
  cached = out;
  return out;
}

/** Agrega `mock_location` si la ubicación del marcaje viene marcada como simulada. */
export function withLocationSignal(base: readonly DeviceIntegritySignal[], location: LocationEvidence | null | undefined): DeviceIntegritySignal[] {
  const list = [...base];
  if (location?.is_mocked === true && !list.includes('mock_location')) list.push('mock_location');
  return list;
}
