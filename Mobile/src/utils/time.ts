/** ISO-8601 UTC sin milisegundos (convención del contrato: 2026-11-17T15:04:05Z). */
export function isoNow(date: Date = new Date()): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** Zona horaria IANA del dispositivo, o la de respaldo indicada. */
export function deviceTimezone(fallback: string): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || fallback;
  } catch {
    return fallback;
  }
}

/** Desfase (ms) de una zona IANA en un instante: hora local − UTC. */
export function zoneOffsetMs(instant: Date, tz: string): number {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: tz,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(instant);
  const get = (t: string) => Number(parts.find((p) => p.type === t)?.value ?? 0);
  const asUtc = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour') % 24, get('minute'), get('second'));
  return asUtc - Math.floor(instant.getTime() / 1000) * 1000;
}

/**
 * Convierte una hora de reloj (fecha civil + HH:mm) en la zona indicada a
 * ISO-8601 UTC. Se usa para la hora propuesta de una rectificación.
 */
export function wallTimeToUtcIso(date: string, hours: number, minutes: number, tz: string): string {
  const [y, m, d] = date.split('-').map(Number) as [number, number, number];
  const guess = Date.UTC(y, m - 1, d, hours, minutes, 0);
  let utc = guess - zoneOffsetMs(new Date(guess), tz);
  // Segunda pasada por si el desfase cambia (horario de verano).
  utc = guess - zoneOffsetMs(new Date(utc), tz);
  return isoNow(new Date(utc));
}
