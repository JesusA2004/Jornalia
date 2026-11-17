import type { Geofence } from '@/types/contracts';

const EARTH_RADIUS_M = 6_371_000;

/** Distancia en metros entre dos coordenadas (fórmula de haversine). */
export function distanceMeters(lat1: number, lon1: number, lat2: number, lon2: number): number {
  const toRad = (d: number) => (d * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLon = toRad(lon2 - lon1);
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
  return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(a)));
}

/**
 * Geocerca más cercana. Es sólo orientativo para la interfaz: la validación
 * oficial la hace el servidor.
 */
export function nearestGeofence(lat: number, lon: number, fences: Geofence[]): { fence: Geofence; distance: number; inside: boolean } | null {
  let best: { fence: Geofence; distance: number; inside: boolean } | null = null;
  for (const fence of fences) {
    const distance = distanceMeters(lat, lon, fence.latitude, fence.longitude);
    if (!best || distance < best.distance) best = { fence, distance, inside: distance <= fence.radius_meters };
  }
  return best;
}
