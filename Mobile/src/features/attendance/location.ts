import * as Location from 'expo-location';

import type { LocationEvidence, LocationMode } from '@/types/contracts';
import { isoNow } from '@/utils/time';

import { PunchFlowError } from './errors';

const LOCATION_TIMEOUT_MS = 20_000;

/** true si el modo de la política exige ubicación para aceptar el marcaje. */
export function locationRequired(mode: LocationMode): boolean {
  return mode === 'required' || mode === 'geofence';
}

/** true si conviene intentar capturar ubicación (aunque no sea obligatoria). */
export function locationWanted(mode: LocationMode): boolean {
  return mode !== 'free' && mode !== 'device_only';
}

function withTimeout<T>(p: Promise<T>, ms: number): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('timeout')), ms);
    p.then(
      (v) => {
        clearTimeout(t);
        resolve(v);
      },
      (e) => {
        clearTimeout(t);
        reject(e);
      },
    );
  });
}

/**
 * Obtiene la ubicación actual en primer plano según la política.
 * Nunca fabrica coordenadas: si no hay lectura real devuelve null (modo
 * "record") o lanza error (modos que la exigen).
 */
export async function captureLocation(mode: LocationMode): Promise<LocationEvidence | null> {
  if (!locationWanted(mode)) return null;
  const required = locationRequired(mode);
  const fail = (code: 'location_denied' | 'location_services_off' | 'location_unavailable', message: string) => {
    if (required) throw new PunchFlowError(code, message);
    return null;
  };

  let perm = await Location.getForegroundPermissionsAsync();
  if (perm.status !== 'granted' && perm.canAskAgain) perm = await Location.requestForegroundPermissionsAsync();
  if (perm.status !== 'granted') {
    return fail('location_denied', 'Tu empresa requiere tu ubicación para marcar. Concede el permiso de ubicación en Ajustes.');
  }
  if (!(await Location.hasServicesEnabledAsync())) {
    return fail('location_services_off', 'Activa la ubicación del teléfono para poder marcar.');
  }
  try {
    let loc: Location.LocationObject;
    try {
      loc = await withTimeout(Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }), LOCATION_TIMEOUT_MS);
    } catch (e) {
      // Sin fijación nueva (p. ej. sin datos): última lectura REAL del SO de hace
      // menos de 5 min y ≤ 100 m, con su propia hora de captura. Nunca se inventa.
      const last = await Location.getLastKnownPositionAsync({ maxAge: 5 * 60_000, requiredAccuracy: 100 });
      if (!last) throw e;
      loc = last;
    }
    return {
      latitude: loc.coords.latitude,
      longitude: loc.coords.longitude,
      accuracy_meters: loc.coords.accuracy ?? null,
      captured_at: isoNow(new Date(loc.timestamp)),
      is_mocked: typeof loc.mocked === 'boolean' ? loc.mocked : null,
    };
  } catch {
    return fail('location_unavailable', 'No se pudo obtener tu ubicación. Sal a un lugar con mejor señal e intenta de nuevo.');
  }
}

export async function locationPermissionStatus(): Promise<Location.PermissionStatus> {
  return (await Location.getForegroundPermissionsAsync()).status;
}
