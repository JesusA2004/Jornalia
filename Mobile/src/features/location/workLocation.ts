/**
 * Ubicación de trabajo en tiempo real — lógica PURA (sin módulos nativos), mismo
 * modelo que la app de Auditoría MR LANA (D-138/D-139):
 *  - filtro de cambios significativos (distancia / latido / precisión);
 *  - descarte de muestras inválidas (coordenadas imposibles, precisión > 500 m,
 *    > ~24 h de antigüedad o > 2 min en el futuro). Nunca se corrigen ni inventan;
 *  - ventana de jornada que define el servidor: fuera de ella la muestra se
 *    descarta antes de guardarse (pausa);
 *  - cola persistente con uuid por punto (servidor idempotente), lotes ≤ 50
 *    marcados ANTES de enviar y borrados al recibir respuesta.
 * Contrato: POST /location/points (LocationBatchRequest → LocationBatchResponse).
 */
import type { LocationBatchResponse, LocationPoint, LocationSettings } from '@/types/contracts';

export type AppStateKind = LocationPoint['app_state'];

/** Lectura del SO antes de convertirse en punto. */
export interface LocationSample {
  latitude: number;
  longitude: number;
  accuracy_m?: number | null;
  heading?: number | null;
  speed_mps?: number | null;
  altitude_m?: number | null;
  /** ISO, hora de la muestra (reloj del teléfono). */
  captured_at: string;
  is_mocked?: boolean | null;
}

/** Puerto mínimo de SQLite (coincide con expo-sqlite) para poder probar sin módulos nativos. */
export interface SqlExecutor {
  runAsync(sql: string, params: (string | number | null)[]): Promise<{ changes: number }>;
  getFirstAsync<T>(sql: string, params: (string | number | null)[]): Promise<T | null>;
  getAllAsync<T>(sql: string, params: (string | number | null)[]): Promise<T[]>;
}

/** Cadencias por omisión (el servidor puede ajustarlas en `settings`). */
export const DEFAULT_CADENCE: Record<AppStateKind, { intervalS: number; distanceM: number; heartbeatS: number }> = {
  foreground: { intervalS: 15, distanceM: 10, heartbeatS: 60 },
  background: { intervalS: 60, distanceM: 50, heartbeatS: 300 },
};

export const MAX_BATCH = 50;
export const MAX_QUEUE = 2000;
export const MAX_AGE_MS = 23.5 * 3600_000;
export const MAX_ACCURACY_M = 500;
export const MAX_FUTURE_MS = 120_000;

export function cadenceFor(state: AppStateKind, settings: LocationSettings | null): { intervalS: number; distanceM: number; heartbeatS: number } {
  const base = DEFAULT_CADENCE[state];
  if (!settings) return base;
  const interval = settings.interval_s?.[state];
  const distance = settings.distance_m?.[state];
  return {
    intervalS: typeof interval === 'number' && interval > 0 ? interval : base.intervalS,
    distanceM: typeof distance === 'number' && distance >= 0 ? distance : base.distanceM,
    heartbeatS: base.heartbeatS,
  };
}

/* ───────────────────────── Ventana de jornada ───────────────────────── */

/**
 * ¿Se comparte la ubicación en el instante `t`?
 *  - Sin ajustes conocidos (primer arranque): sí; decide el servidor.
 *  - `enabled=false`: no (la empresa no requiere ubicación a este colaborador).
 *  - Dentro de [starts_at, ends_at): sí. Desde `next_start_at`: sí (el servidor
 *    enviará la ventana nueva en la siguiente respuesta).
 */
export function windowActive(settings: LocationSettings | null, t: number): boolean {
  if (!settings) return true;
  const w = settings.window;
  if (!w.enabled) return false;
  const start = w.starts_at ? Date.parse(w.starts_at) : NaN;
  const end = w.ends_at ? Date.parse(w.ends_at) : NaN;
  if (!Number.isNaN(start) && !Number.isNaN(end) && t >= start && t < end) return true;
  const next = w.next_start_at ? Date.parse(w.next_start_at) : NaN;
  if (!Number.isNaN(next) && t >= next) return true;
  if (Number.isNaN(start) && Number.isNaN(end) && Number.isNaN(next)) return w.active_now;
  return false;
}

/** Valida ajustes recibidos (o guardados) antes de usarlos. */
export function parseSettings(v: unknown): LocationSettings | null {
  if (!v || typeof v !== 'object') return null;
  const o = v as Partial<LocationSettings>;
  const w = o.window as Partial<LocationSettings['window']> | undefined;
  if (!w || typeof w.enabled !== 'boolean' || typeof w.active_now !== 'boolean') return null;
  const inst = (x: unknown) => x === null || x === undefined || (typeof x === 'string' && !Number.isNaN(Date.parse(x)));
  if (!inst(w.starts_at) || !inst(w.ends_at) || !inst(w.next_start_at)) return null;
  return {
    window: {
      enabled: w.enabled,
      active_now: w.active_now,
      starts_at: w.starts_at ?? null,
      ends_at: w.ends_at ?? null,
      next_start_at: w.next_start_at ?? null,
      timezone: typeof w.timezone === 'string' ? w.timezone : 'America/Mexico_City',
    },
    interval_s: {
      foreground: Number(o.interval_s?.foreground) || DEFAULT_CADENCE.foreground.intervalS,
      background: Number(o.interval_s?.background) || DEFAULT_CADENCE.background.intervalS,
    },
    distance_m: {
      foreground: Number.isFinite(Number(o.distance_m?.foreground)) ? Number(o.distance_m?.foreground) : DEFAULT_CADENCE.foreground.distanceM,
      background: Number.isFinite(Number(o.distance_m?.background)) ? Number(o.distance_m?.background) : DEFAULT_CADENCE.background.distanceM,
    },
  };
}

/* ───────────────────────── Filtro ───────────────────────── */

export function distanceM(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
  const R = 6_371_000;
  const rad = (g: number) => (g * Math.PI) / 180;
  const dLat = rad(b.latitude - a.latitude);
  const dLon = rad(b.longitude - a.longitude);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
}

function validCoords(s: { latitude: number; longitude: number }): boolean {
  return (
    Number.isFinite(s.latitude) &&
    Number.isFinite(s.longitude) &&
    Math.abs(s.latitude) <= 90 &&
    Math.abs(s.longitude) <= 180 &&
    !(s.latitude === 0 && s.longitude === 0)
  );
}

export type LastAccepted = { latitude: number; longitude: number; captured_at: string } | null;

/** ¿Vale la pena enviar `current` después de `previous`? */
export function isSignificant(
  previous: LastAccepted,
  current: LocationSample,
  state: AppStateKind,
  settings: LocationSettings | null = null,
): boolean {
  if (!validCoords(current)) return false;
  const t = Date.parse(current.captured_at);
  if (Number.isNaN(t)) return false;
  if (current.accuracy_m != null && current.accuracy_m > MAX_ACCURACY_M) return false;
  if (!previous) return true;
  const t0 = Date.parse(previous.captured_at);
  if (Number.isNaN(t0)) return true;
  const elapsedS = (t - t0) / 1000;
  if (elapsedS <= 0) return false;
  const c = cadenceFor(state, settings);
  if (elapsedS >= c.heartbeatS) return true;
  const threshold = Math.max(c.distanceM, Math.min(current.accuracy_m ?? 0, 100));
  return distanceM(previous, current) >= threshold;
}

export function filterSamples(
  last: LastAccepted,
  samples: LocationSample[],
  state: AppStateKind,
  settings: LocationSettings | null = null,
): { accepted: LocationSample[]; last: LastAccepted } {
  const sorted = [...samples].sort((a, b) => Date.parse(a.captured_at) - Date.parse(b.captured_at));
  const accepted: LocationSample[] = [];
  let l = last;
  for (const s of sorted) {
    if (!isSignificant(l, s, state, settings)) continue;
    accepted.push(s);
    l = { latitude: s.latitude, longitude: s.longitude, captured_at: s.captured_at };
  }
  return { accepted, last: l };
}

const finiteOrNull = (v: number | null | undefined): number | null => (v != null && Number.isFinite(v) ? v : null);

/** Punto del contrato. Los valores "desconocidos" del SO (-1) pasan a null. */
export function buildPoint(s: LocationSample, state: AppStateKind, uuid: string): LocationPoint {
  const heading = finiteOrNull(s.heading);
  const speed = finiteOrNull(s.speed_mps);
  return {
    uuid,
    latitude: s.latitude,
    longitude: s.longitude,
    accuracy_m: finiteOrNull(s.accuracy_m),
    heading: heading !== null && heading >= 0 && heading <= 360 ? heading : null,
    speed_mps: speed !== null && speed >= 0 ? speed : null,
    altitude_m: finiteOrNull(s.altitude_m),
    captured_at: new Date(Date.parse(s.captured_at)).toISOString().replace(/\.\d{3}Z$/, 'Z'),
    app_state: state,
    is_mocked: typeof s.is_mocked === 'boolean' ? s.is_mocked : null,
  };
}

/* ───────────────────────── Persistencia ───────────────────────── */

type StateRow = { last_json: string | null; last_sent_at: string | null; last_error: string | null; last_error_at: string | null };

function parseLast(text: string | null | undefined): LastAccepted {
  if (!text) return null;
  try {
    const o = JSON.parse(text) as Partial<NonNullable<LastAccepted>>;
    if (typeof o.latitude === 'number' && typeof o.longitude === 'number' && typeof o.captured_at === 'string') {
      return { latitude: o.latitude, longitude: o.longitude, captured_at: o.captured_at };
    }
  } catch {
    // se reinicia el filtro
  }
  return null;
}

async function ensureState(db: SqlExecutor, userId: number): Promise<StateRow | null> {
  await db.runAsync('INSERT OR IGNORE INTO location_state (user_id) VALUES (?)', [userId]);
  return db.getFirstAsync<StateRow>('SELECT last_json, last_sent_at, last_error, last_error_at FROM location_state WHERE user_id = ?', [userId]);
}

/** Elimina lo que el servidor rechazaría (muy antiguo) y limita la cola. */
export async function pruneQueue(db: SqlExecutor, userId: number, now: number): Promise<void> {
  const limit = new Date(now - MAX_AGE_MS).toISOString();
  await db.runAsync('DELETE FROM location_queue WHERE user_id = ? AND batch_uuid IS NULL AND captured_at < ?', [userId, limit]);
  const row = await db.getFirstAsync<{ n: number }>('SELECT COUNT(*) AS n FROM location_queue WHERE user_id = ?', [userId]);
  const extra = Number(row?.n ?? 0) - MAX_QUEUE;
  if (extra > 0) {
    await db.runAsync(
      `DELETE FROM location_queue WHERE user_id = ? AND uuid IN (
         SELECT uuid FROM location_queue WHERE user_id = ? AND batch_uuid IS NULL ORDER BY captured_at ASC LIMIT ?)`,
      [userId, userId, extra],
    );
  }
}

/**
 * Filtra y guarda muestras del SO. Devuelve cuántos puntos se encolaron.
 * Fuera de la ventana de jornada no guarda nada (pausa).
 */
export async function storeSamples(
  db: SqlExecutor,
  userId: number,
  samples: LocationSample[],
  state: AppStateKind,
  uuid: () => string,
  now: number,
  settings: LocationSettings | null,
): Promise<number> {
  const recent = samples.filter((s) => {
    const t = Date.parse(s.captured_at);
    return !Number.isNaN(t) && t >= now - MAX_AGE_MS && t <= now + MAX_FUTURE_MS && windowActive(settings, t);
  });
  if (!recent.length) return 0;
  const row = await ensureState(db, userId);
  const { accepted, last } = filterSamples(parseLast(row?.last_json), recent, state, settings);
  if (!accepted.length) return 0;
  let inserted = 0;
  for (const s of accepted) {
    const p = buildPoint(s, state, uuid());
    const r = await db.runAsync('INSERT OR IGNORE INTO location_queue (user_id, uuid, point, captured_at, batch_uuid) VALUES (?, ?, ?, ?, NULL)', [
      userId,
      p.uuid,
      JSON.stringify(p),
      p.captured_at,
    ]);
    inserted += r.changes;
  }
  await db.runAsync('UPDATE location_state SET last_json = ? WHERE user_id = ?', [JSON.stringify(last), userId]);
  await pruneQueue(db, userId, now);
  return inserted;
}

export async function discardLocation(db: SqlExecutor, userId: number): Promise<void> {
  await db.runAsync('DELETE FROM location_queue WHERE user_id = ?', [userId]);
  await db.runAsync('DELETE FROM location_state WHERE user_id = ?', [userId]);
}

/** Libera lotes que quedaron marcados por un envío interrumpido. */
export async function rearmQueue(db: SqlExecutor, userId: number): Promise<void> {
  await db.runAsync('UPDATE location_queue SET batch_uuid = NULL WHERE user_id = ? AND batch_uuid IS NOT NULL', [userId]);
}

export type UploadErrorKind = 'session' | 'forbidden' | 'validation' | 'network' | 'server';

export type UploadResult =
  | { status: 'empty' }
  | { status: 'sent'; sent: number; response: LocationBatchResponse | null }
  | { status: 'rejected'; kind: 'session' | 'forbidden'; sent: number }
  | { status: 'failed'; kind: UploadErrorKind; sent: number };

/**
 * Envía la cola en lotes ≤ 50. Cada lote se marca con `batch_uuid` antes de la
 * petición; con respuesta (o 422 del lote completo) se borra; con otro fallo se
 * libera para reintentar.
 */
export async function uploadPending(
  db: SqlExecutor,
  userId: number,
  send: (points: LocationPoint[]) => Promise<LocationBatchResponse>,
  classify: (e: unknown) => UploadErrorKind,
  newUuid: () => string,
  now: () => number,
  maxBatches = 5,
): Promise<UploadResult> {
  await ensureState(db, userId);
  await pruneQueue(db, userId, now());
  let sent = 0;
  let lastResponse: LocationBatchResponse | null = null;
  for (let i = 0; i < maxBatches; i++) {
    const batch = newUuid();
    await db.runAsync(
      `UPDATE location_queue SET batch_uuid = ? WHERE user_id = ? AND uuid IN (
         SELECT uuid FROM location_queue WHERE user_id = ? AND batch_uuid IS NULL ORDER BY captured_at ASC LIMIT ?)`,
      [batch, userId, userId, MAX_BATCH],
    );
    const rows = await db.getAllAsync<{ point: string }>(
      'SELECT point FROM location_queue WHERE user_id = ? AND batch_uuid = ? ORDER BY captured_at ASC',
      [userId, batch],
    );
    if (!rows.length) break;
    const points = rows.map((r) => JSON.parse(r.point) as LocationPoint);
    try {
      lastResponse = await send(points);
      await db.runAsync('DELETE FROM location_queue WHERE user_id = ? AND batch_uuid = ?', [userId, batch]);
      sent += points.length;
      await db.runAsync('UPDATE location_state SET last_sent_at = ?, last_error = NULL, last_error_at = NULL WHERE user_id = ?', [
        new Date(now()).toISOString(),
        userId,
      ]);
    } catch (e) {
      const kind = classify(e);
      if (kind === 'validation') {
        await db.runAsync('DELETE FROM location_queue WHERE user_id = ? AND batch_uuid = ?', [userId, batch]);
      } else {
        await db.runAsync('UPDATE location_queue SET batch_uuid = NULL WHERE user_id = ? AND batch_uuid = ?', [userId, batch]);
      }
      await db.runAsync('UPDATE location_state SET last_error = ?, last_error_at = ? WHERE user_id = ?', [kind, new Date(now()).toISOString(), userId]);
      if (kind === 'session' || kind === 'forbidden') return { status: 'rejected', kind, sent };
      if (kind === 'validation') continue;
      return { status: 'failed', kind, sent };
    }
  }
  return sent === 0 && lastResponse === null ? { status: 'empty' } : { status: 'sent', sent, response: lastResponse };
}

export interface LocationSummary {
  pending: number;
  lastCapturedAt: string | null;
  lastSentAt: string | null;
  lastError: string | null;
}

export async function locationSummary(db: SqlExecutor, userId: number): Promise<LocationSummary> {
  const n = await db.getFirstAsync<{ n: number }>('SELECT COUNT(*) AS n FROM location_queue WHERE user_id = ?', [userId]);
  const st = await db.getFirstAsync<StateRow>('SELECT last_json, last_sent_at, last_error, last_error_at FROM location_state WHERE user_id = ?', [userId]);
  return {
    pending: Number(n?.n ?? 0),
    lastCapturedAt: parseLast(st?.last_json)?.captured_at ?? null,
    lastSentAt: st?.last_sent_at ?? null,
    lastError: st?.last_error ?? null,
  };
}

/* ───────────────────────── Estado visible ───────────────────────── */

export type TrackingKey = 'active' | 'active_foreground_only' | 'starting' | 'paused_window' | 'stopped' | 'no_permission' | 'gps_off' | 'not_required' | 'unavailable';

export interface TrackingInput {
  available: boolean;
  foreground: 'granted' | 'denied' | 'undetermined';
  background: 'granted' | 'denied' | 'undetermined';
  gpsOn: boolean;
  running: boolean | null;
  notRequired: boolean;
  outsideWindow: boolean;
}

export function trackingKey(i: TrackingInput): TrackingKey {
  if (!i.available) return 'unavailable';
  if (i.notRequired) return 'not_required';
  if (i.foreground !== 'granted') return 'no_permission';
  if (!i.gpsOn) return 'gps_off';
  if (i.outsideWindow) return 'paused_window';
  if (i.running === null) return 'starting';
  if (!i.running) return 'stopped';
  return i.background === 'granted' ? 'active' : 'active_foreground_only';
}
