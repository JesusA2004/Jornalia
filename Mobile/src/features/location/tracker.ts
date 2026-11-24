/**
 * ÚNICO adaptador nativo de la ubicación de trabajo (mismo modelo que la app de
 * Auditoría, D-138/D-139). Es el único lugar que toca las actualizaciones en
 * segundo plano de expo-location, expo-task-manager y el servicio en primer plano.
 *
 *  - Una sola tarea de GPS con servicio en primer plano y aviso visible
 *    «Ubicación de trabajo activa».
 *  - Android 12+ no permite iniciar el servicio desde segundo plano: se (re)inicia
 *    cuando la app está en pantalla (al abrirla).
 *  - La tarea está ligada a la sesión: en cada entrega relee la credencial; sin
 *    sesión o con otro usuario se detiene y descarta lo no enviado.
 *  - El GPS sólo corre en la fase «active» de la jornada (modo «during_shift»):
 *    inicia con la entrada, se detiene en descanso/comida y con la salida. En
 *    modo «on_punch» nunca hay seguimiento (sólo ubicación al marcar).
 *  - Ahorro de batería: precisión equilibrada en segundo plano (60 s / 50 m) y
 *    alta sólo con la app en pantalla.
 *  - Nunca se inventan coordenadas; `is_mocked` viaja tal como lo reporta el SO.
 */
import * as Crypto from 'expo-crypto';
import * as Location from 'expo-location';
import * as SecureStore from 'expo-secure-store';
import * as TaskManager from 'expo-task-manager';
import { AppState, Linking, Platform } from 'react-native';

import { ApiRequestError, NetworkError } from '@/api/errors';
import { api } from '@/services/container';
import { getDb } from '@/storage/db';
import { getSession } from '@/storage/secure';
import { brand } from '@/theme/tokens';
import type { LocationSettings } from '@/types/contracts';

import {
  type AppStateKind,
  cadenceFor,
  discardLocation,
  type LocationSample,
  parseSettings,
  rearmQueue,
  type SqlExecutor,
  storeSamples,
  type TrackingPhase,
  type UploadErrorKind,
  uploadPending,
} from './workLocation';

export const LOCATION_TASK = 'mrlana-ubicacion-trabajo';

const KEY_CONTEXT = 'location.context';
const KEY_SETTINGS = 'location.settings';
const KEY_NOT_REQUIRED = 'location.not_required';
const KEY_MODE_STARTED = 'location.started';
const KEY_PHASE = 'location.phase';

const UPLOAD_INTERVAL_BG_MS = 60_000;
let lastBackgroundUpload = 0;
/** Este proceso ya emitió la petición estando en pantalla (servicio adjunto). */
let serviceInThisProcess = false;

type Context = { userId: number };

/* ─────────────────────────── Estado en SecureStore ─────────────────────────── */

async function readContext(): Promise<Context | null> {
  try {
    const raw = await SecureStore.getItemAsync(KEY_CONTEXT);
    if (!raw) return null;
    const o = JSON.parse(raw) as Partial<Context>;
    return typeof o.userId === 'number' ? { userId: o.userId } : null;
  } catch {
    return null;
  }
}

export async function readSettings(): Promise<LocationSettings | null> {
  try {
    const raw = await SecureStore.getItemAsync(KEY_SETTINGS);
    return raw ? parseSettings(JSON.parse(raw)) : null;
  } catch {
    return null;
  }
}

export async function saveSettings(v: unknown): Promise<LocationSettings | null> {
  const s = parseSettings(v);
  if (s) await SecureStore.setItemAsync(KEY_SETTINGS, JSON.stringify(s)).catch(() => undefined);
  return s;
}

export async function isNotRequired(userId: number): Promise<boolean> {
  return (await SecureStore.getItemAsync(KEY_NOT_REQUIRED).catch(() => null)) === String(userId);
}

/** Fase persistida (la lee la tarea en segundo plano). Por omisión «off». */
export async function readPhase(): Promise<TrackingPhase> {
  const v = await SecureStore.getItemAsync(KEY_PHASE).catch(() => null);
  return v === 'active' || v === 'paused' ? v : 'off';
}

async function del(key: string) {
  await SecureStore.deleteItemAsync(key).catch(() => undefined);
}

/** Credencial vigente del mismo usuario que inició la tarea. */
async function tokenFor(ctx: Context): Promise<string | null> {
  const s = await getSession().catch(() => null);
  if (!s?.token || s.userId !== ctx.userId) return null;
  return s.token;
}

/* ─────────────────────────── Actualizaciones del SO ─────────────────────────── */

const SERVICE = {
  notificationTitle: 'Ubicación de trabajo activa',
  notificationBody: 'Sólo durante tu jornada. Se pausa en descanso y se detiene con tu salida.',
  notificationColor: brand.green,
};

function taskOptions(settings: LocationSettings | null): Location.LocationTaskOptions {
  const c = cadenceFor('background', settings);
  return {
    // Equilibrada: fusiona red/Wi-Fi/GPS sin mantener el GPS fino encendido.
    accuracy: Location.Accuracy.Balanced,
    timeInterval: c.intervalS * 1000,
    distanceInterval: c.distanceM,
    deferredUpdatesInterval: 60_000,
    pausesUpdatesAutomatically: false,
    showsBackgroundLocationIndicator: true,
    foregroundService: SERVICE,
  };
}

export async function isRunning(): Promise<boolean> {
  try {
    return await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK);
  } catch {
    return false;
  }
}

async function stopUpdates(): Promise<void> {
  try {
    if (await isRunning()) await Location.stopLocationUpdatesAsync(LOCATION_TASK);
  } catch {
    // ya detenida
  }
  serviceInThisProcess = false;
  await del(KEY_MODE_STARTED);
}

/**
 * Inicia (o reconfigura) la tarea. Sólo con la app en pantalla (Android 12+);
 * desde segundo plano devuelve el estado actual sin tocar el SO.
 */
async function ensureUpdates(): Promise<boolean> {
  const running = await isRunning();
  if (running && (serviceInThisProcess || AppState.currentState !== 'active')) return true;
  if (AppState.currentState !== 'active') return running;
  try {
    await Location.startLocationUpdatesAsync(LOCATION_TASK, taskOptions(await readSettings()));
  } catch {
    return running;
  }
  await SecureStore.setItemAsync(KEY_MODE_STARTED, '1').catch(() => undefined);
  serviceInThisProcess = true;
  return true;
}

async function endWork(userId: number | null, discard: boolean): Promise<void> {
  await del(KEY_CONTEXT);
  await stopUpdates();
  if (discard && userId !== null) {
    try {
      await discardLocation((await getDb()) as unknown as SqlExecutor, userId);
    } catch {
      // nada guardado
    }
  }
}

/* ─────────────────────────── Envío ─────────────────────────── */

export function classifyUploadError(e: unknown): UploadErrorKind {
  if (e instanceof NetworkError) return 'network';
  if (e instanceof ApiRequestError) {
    if (e.status === 401 || e.code.startsWith('device_')) return 'session';
    if (e.status === 403) return 'forbidden';
    if (e.status === 422) return 'validation';
    return 'server';
  }
  return 'server';
}

/** Envía lo pendiente; guarda los ajustes que responda el servidor. */
export async function uploadNow(userId: number): Promise<UploadErrorKind | 'ok' | 'empty'> {
  const db = (await getDb()) as unknown as SqlExecutor;
  const r = await uploadPending(db, userId, (points) => api.location.points({ points }), classifyUploadError, () => Crypto.randomUUID(), Date.now);
  if (r.status === 'sent') {
    if (r.response?.settings) await saveSettings(r.response.settings);
    return 'ok';
  }
  if (r.status === 'empty') return 'empty';
  if (r.status === 'rejected') {
    if (r.kind === 'forbidden') await SecureStore.setItemAsync(KEY_NOT_REQUIRED, String(userId)).catch(() => undefined);
    await endWork(userId, true);
    return r.kind;
  }
  return r.kind;
}

const sampleOf = (l: Location.LocationObject): LocationSample => ({
  latitude: l.coords.latitude,
  longitude: l.coords.longitude,
  accuracy_m: l.coords.accuracy ?? null,
  heading: l.coords.heading ?? null,
  speed_mps: l.coords.speed ?? null,
  altitude_m: l.coords.altitude ?? null,
  captured_at: new Date(l.timestamp).toISOString(),
  is_mocked: typeof l.mocked === 'boolean' ? l.mocked : null,
});

const currentAppState = (): AppStateKind => (AppState.currentState === 'active' ? 'foreground' : 'background');

/** Guarda muestras (de la tarea o del seguimiento en primer plano) por el mismo filtro. */
export async function ingest(userId: number, samples: LocationSample[], state: AppStateKind): Promise<number> {
  const db = (await getDb()) as unknown as SqlExecutor;
  return storeSamples(db, userId, samples, state, () => Crypto.randomUUID(), Date.now(), await readSettings(), true);
}

// La tarea se define a nivel de módulo para que el SO pueda relanzar la app en
// segundo plano directamente en ella.
if (Platform.OS !== 'web') {
  try {
    TaskManager.defineTask(LOCATION_TASK, async (body: { data?: unknown; error?: unknown }) => {
      if (body.error) return;
      const locations = (body.data as { locations?: Location.LocationObject[] } | undefined)?.locations ?? [];
      if (!locations.length) return;
      const ctx = await readContext();
      if (!ctx) {
        await stopUpdates(); // nunca un GPS sin sesión
        return;
      }
      if ((await readPhase()) !== 'active') {
        await stopUpdates(); // en pausa o fuera de jornada: sin GPS
        return;
      }
      const token = await tokenFor(ctx);
      if (!token) {
        await endWork(ctx.userId, true);
        return;
      }
      try {
        await ingest(ctx.userId, locations.map(sampleOf), currentAppState());
        if (Date.now() - lastBackgroundUpload >= UPLOAD_INTERVAL_BG_MS) {
          lastBackgroundUpload = Date.now();
          await uploadNow(ctx.userId).catch(() => undefined);
        }
      } catch {
        // Falla transitoria (o base sin cifrado): no se guarda nada en claro ni se inventa.
      }
    });
  } catch {
    // definir la tarea nunca debe tumbar la app
  }
}

/* ─────────────────────────── Permisos ─────────────────────────── */

export type PermissionState = 'granted' | 'denied' | 'undetermined';

export interface LocationReading {
  available: boolean;
  foreground: PermissionState;
  canAskForeground: boolean;
  background: PermissionState;
  canAskBackground: boolean;
  gpsOn: boolean;
}

const perm = (s: Location.PermissionStatus): PermissionState =>
  s === Location.PermissionStatus.GRANTED ? 'granted' : s === Location.PermissionStatus.DENIED ? 'denied' : 'undetermined';

/** Lectura silenciosa (nunca muestra diálogos). */
export async function readLocation(): Promise<LocationReading> {
  const none: LocationReading = {
    available: false,
    foreground: 'undetermined',
    canAskForeground: false,
    background: 'undetermined',
    canAskBackground: false,
    gpsOn: false,
  };
  if (Platform.OS === 'web') return none;
  try {
    const [fg, bg, gps] = await Promise.all([
      Location.getForegroundPermissionsAsync(),
      Location.getBackgroundPermissionsAsync().catch(() => null),
      Location.hasServicesEnabledAsync().catch(() => true),
    ]);
    return {
      available: true,
      foreground: perm(fg.status),
      canAskForeground: fg.canAskAgain,
      background: bg ? perm(bg.status) : 'undetermined',
      canAskBackground: bg ? bg.canAskAgain : false,
      gpsOn: gps,
    };
  } catch {
    return none;
  }
}

export async function requestForeground(): Promise<PermissionState> {
  try {
    return perm((await Location.requestForegroundPermissionsAsync()).status);
  } catch {
    return 'denied';
  }
}

/** «Permitir todo el tiempo». En Android 11+ el SO abre su propia página. */
export async function requestBackground(): Promise<PermissionState> {
  try {
    return perm((await Location.requestBackgroundPermissionsAsync()).status);
  } catch {
    return 'denied';
  }
}

export async function openAppSettings(): Promise<void> {
  await Linking.openSettings().catch(() => undefined);
}

export async function turnOnGps(): Promise<void> {
  if (Platform.OS === 'android') {
    try {
      await Location.enableNetworkProviderAsync();
      return;
    } catch {
      // cancelado: ajustes
    }
  }
  await Linking.openSettings().catch(() => undefined);
}

/* ─────────────────────────── Ciclo de vida ─────────────────────────── */

export type StartResult = 'active' | 'no_permission' | 'not_required' | 'waiting' | 'unavailable';

/**
 * Inicia (o mantiene) la ubicación de trabajo de la sesión. Idempotente; nunca
 * muestra diálogos. En Android basta el permiso de primer plano porque el
 * servicio se inicia con la app en pantalla; «todo el tiempo» se pide aparte.
 */
export async function startWorkLocation(userId: number): Promise<StartResult> {
  if (Platform.OS === 'web') return 'unavailable';
  if (await isNotRequired(userId)) return 'not_required';
  const r = await readLocation();
  if (r.foreground !== 'granted') return 'no_permission';
  const prev = await readContext();
  if (prev && prev.userId !== userId) await endWork(prev.userId, true);
  await SecureStore.setItemAsync(KEY_CONTEXT, JSON.stringify({ userId }));
  try {
    await rearmQueue((await getDb()) as unknown as SqlExecutor, userId);
  } catch {
    // la base se reintenta en la siguiente entrega
  }
  if ((await readPhase()) !== 'active') {
    await stopUpdates();
    return 'waiting';
  }
  return (await ensureUpdates()) ? 'active' : 'waiting';
}

/**
 * Aplica la fase de la jornada: «active» enciende el seguimiento (sólo con la
 * app en pantalla, que es cuando se marca); «paused»/«off» lo apagan y envían
 * lo pendiente. Idempotente.
 */
export async function applyPhase(userId: number, phase: TrackingPhase): Promise<StartResult> {
  await SecureStore.setItemAsync(KEY_PHASE, phase).catch(() => undefined);
  if (phase === 'active') return startWorkLocation(userId);
  await stopUpdates();
  await uploadNow(userId).catch(() => undefined);
  return 'waiting';
}

/** Ajusta la cadencia tras recibir ajustes nuevos (sólo en pantalla). */
export async function reconfigure(): Promise<void> {
  if (AppState.currentState !== 'active' || !(await readContext())) return;
  serviceInThisProcess = false;
  await ensureUpdates();
}

/** Cierre de sesión / 401: detiene el GPS y descarta lo no enviado. */
export async function stopWorkLocation(): Promise<void> {
  const ctx = await readContext();
  await del(KEY_NOT_REQUIRED);
  await del(KEY_SETTINGS);
  await del(KEY_PHASE);
  await endWork(ctx?.userId ?? null, true);
}

/** Al arrancar: si se retiró el permiso, se detiene la tarea heredada (Android 14+). */
export async function reconcileOnLaunch(): Promise<void> {
  try {
    if (!(await isRunning())) return;
    const fg = await Location.getForegroundPermissionsAsync();
    if (fg.status !== Location.PermissionStatus.GRANTED || (await readPhase()) !== 'active') await stopUpdates();
  } catch {
    // sin efecto
  }
}

/** Seguimiento ligero en primer plano (~15 s / 10 m) que alimenta la misma cola. */
export async function watchForeground(userId: number, onSample?: () => void): Promise<() => void> {
  const settings = await readSettings();
  const c = cadenceFor('foreground', settings);
  let alive = true;
  const feed = (l: Location.LocationObject) => {
    // Sólo en pantalla: en segundo plano manda la tarea (60 s / 50 m, app_state=background).
    if (!alive || AppState.currentState !== 'active') return;
    void ingest(userId, [sampleOf(l)], 'foreground')
      .then(() => onSample?.())
      .catch(() => undefined);
  };
  const sub = await Location.watchPositionAsync(
    { accuracy: Location.Accuracy.High, timeInterval: c.intervalS * 1000, distanceInterval: c.distanceM },
    feed,
  );
  void Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }).then(feed).catch(() => undefined);
  return () => {
    alive = false;
    sub.remove();
  };
}
