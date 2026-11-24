import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { useToday } from '@/features/attendance/TodayProvider';
import { useSync } from '@/features/offline/SyncProvider';
import { api } from '@/services/container';
import { getDb } from '@/storage/db';
import type { AttendancePolicy, LocationSettings, LocationTracking } from '@/types/contracts';

import {
  applyPhase,
  isNotRequired,
  isRunning,
  type LocationReading,
  openAppSettings,
  readLocation,
  readPhase,
  readSettings,
  reconcileOnLaunch,
  reconfigure,
  requestBackground,
  requestForeground,
  saveSettings,
  startWorkLocation,
  stopWorkLocation,
  turnOnGps,
  uploadNow,
  watchForeground,
} from './tracker';
import {
  locationSummary,
  type LocationSummary,
  type SqlExecutor,
  STATUS_AFTER_PUNCH,
  type TrackingKey,
  trackingKey,
  type TrackingPhase,
  trackingPhase,
  windowActive,
} from './workLocation';

interface LocationValue {
  key: TrackingKey;
  reading: LocationReading | null;
  settings: LocationSettings | null;
  summary: LocationSummary | null;
  /** Modo del puesto: sólo al marcar o seguimiento durante la jornada. */
  mode: LocationTracking | null;
  phase: TrackingPhase;
  /** true mientras el teléfono comparte ubicación de trabajo (indicador visible). */
  sharing: boolean;
  /** Pide permiso de primer plano y, después, «todo el tiempo». */
  enable: () => Promise<void>;
  turnOnGps: () => Promise<void>;
  openSettings: () => Promise<void>;
  refresh: () => Promise<void>;
}

const LocationContext = createContext<LocationValue | null>(null);

/** Epoch ms redondeado al minuto (se actualiza cada minuto). */
function useMinute(): number {
  const [m, setM] = useState(() => Math.floor(Date.now() / 60_000) * 60_000);
  useEffect(() => {
    const id = setInterval(() => setM(Math.floor(Date.now() / 60_000) * 60_000), 60_000);
    return () => clearInterval(id);
  }, []);
  return m;
}

const FOREGROUND_UPLOAD_MS = 30_000;

/**
 * Ubicación de trabajo de la sesión. La fase la decide la jornada (entrada,
 * descanso/comida, salida) y el modo del puesto; la tarea única se enciende
 * sólo en fase «active» (Android 12+ exige iniciarla en pantalla, que es cuando
 * se marca), alimenta la cola en primer plano, envía cada 30 s y se detiene al
 * cerrar sesión.
 */
export function LocationProvider({ userId, children }: { userId: number; children: ReactNode }) {
  const [reading, setReading] = useState<LocationReading | null>(null);
  const [running, setRunning] = useState<boolean | null>(null);
  const [settings, setSettings] = useState<LocationSettings | null>(null);
  const [summary, setSummary] = useState<LocationSummary | null>(null);
  const [notRequired, setNotRequired] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const stopWatch = useRef<(() => void) | null>(null);
  const startingWatch = useRef(false);

  // Fase según la jornada (con marcajes aún sin enviar, manda el último de ellos).
  const today = useToday();
  const { items } = useSync();
  const policy = (today.data?.policy ?? null) as AttendancePolicy | null;
  const mode: LocationTracking | null = policy ? (policy.location_tracking ?? 'on_punch') : null;
  const lastPending = items.filter((i) => i.status === 'pending').at(-1)?.request.type ?? null;
  const status = lastPending ? STATUS_AFTER_PUNCH[lastPending] : (today.data?.workday.status ?? null);
  // Reloj de minuto: corta el seguimiento si se omitió la salida (contingencia del contrato).
  const minute = useMinute();
  const computed = policy
    ? trackingPhase(mode, status, policy.tracking_paused_states ?? ['break', 'meal'], {
        shiftEndsAt: today.data?.workday.shift?.ends_at ?? null,
        contingencyMinutes: policy.location_contingency_minutes ?? null,
        now: minute,
      })
    : null;
  const [storedPhase, setStoredPhase] = useState<TrackingPhase>('off');
  const phase: TrackingPhase = computed ?? storedPhase;
  const phaseRef = useRef<TrackingPhase>('off');

  const loadSummary = useCallback(async () => {
    try {
      setSummary(await locationSummary((await getDb()) as unknown as SqlExecutor, userId));
    } catch {
      // base no disponible
    }
  }, [userId]);

  const sync = useCallback(async () => {
    const r = await readLocation();
    setReading(r);
    setNotRequired(await isNotRequired(userId));
    const active = phaseRef.current === 'active';
    if (!active) {
      stopWatch.current?.();
      stopWatch.current = null;
    }
    if (r.foreground === 'granted' && active) {
      await startWorkLocation(userId);
      if (AppState.currentState === 'active' && !stopWatch.current && !startingWatch.current && r.gpsOn) {
        // Un solo seguimiento de primer plano aunque sync() se llame en paralelo.
        startingWatch.current = true;
        try {
          const stop = await watchForeground(userId, () => void loadSummary()).catch(() => null);
          if (AppState.currentState === 'active' && !stopWatch.current) stopWatch.current = stop;
          else stop?.();
        } finally {
          startingWatch.current = false;
        }
      }
    }
    setRunning(await isRunning());
    setSettings(await readSettings());
    setNow(Date.now());
    await loadSummary();
  }, [userId, loadSummary]);

  const upload = useCallback(async () => {
    const r = await uploadNow(userId).catch(() => null);
    if (r === 'forbidden') setNotRequired(true);
    setSettings(await readSettings());
    setNow(Date.now());
    await loadSummary();
  }, [userId, loadSummary]);

  // Arranque: reconciliar, pedir ajustes al servidor y comenzar.
  useEffect(() => {
    let cancelled = false;
    void (async () => {
      await reconcileOnLaunch();
      try {
        const state = await api.location.state();
        if (await saveSettings(state.settings)) await reconfigure();
      } catch {
        // sin red: se usan los últimos ajustes guardados
      }
      if (!cancelled) await sync();
    })();
    return () => {
      cancelled = true;
    };
  }, [sync]);

  // Primer plano: reanudar; segundo plano: quitar el seguimiento ligero.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') void sync().then(upload);
      else {
        stopWatch.current?.();
        stopWatch.current = null;
      }
    });
    const timer = setInterval(() => {
      if (AppState.currentState === 'active') void upload();
    }, FOREGROUND_UPLOAD_MS);
    return () => {
      sub.remove();
      clearInterval(timer);
      stopWatch.current?.();
      stopWatch.current = null;
    };
  }, [sync, upload]);

  // Fase sin política aún (arranque sin red): se conserva la última guardada.
  useEffect(() => {
    if (computed !== null) return;
    void readPhase().then((p) => {
      phaseRef.current = p;
      setStoredPhase(p);
    });
  }, [computed]);

  // Cambio de fase por un marcaje: encender, pausar o detener el seguimiento.
  useEffect(() => {
    if (computed === null) return;
    phaseRef.current = computed;
    void applyPhase(userId, computed).then(() => sync());
  }, [computed, userId, sync]);

  // Detener al salir de la sesión (desmontaje del área autenticada).
  useEffect(() => {
    return () => {
      void stopWorkLocation();
    };
  }, [userId]);

  const enable = useCallback(async () => {
    const current = await readLocation();
    if (current.foreground !== 'granted') {
      if (!current.canAskForeground && current.foreground === 'denied') {
        await openAppSettings();
        return;
      }
      if ((await requestForeground()) !== 'granted') {
        await sync();
        return;
      }
    }
    await sync();
    const after = await readLocation();
    if (after.background !== 'granted' && after.canAskBackground) await requestBackground();
    await sync();
  }, [sync]);

  const key = trackingKey({
    available: reading?.available ?? true,
    foreground: reading?.foreground ?? 'undetermined',
    background: reading?.background ?? 'undetermined',
    gpsOn: reading?.gpsOn ?? true,
    running: reading ? running : null,
    notRequired,
    outsideWindow: settings ? !windowActive(settings, now) : false,
    mode,
    phase,
  });
  const sharing = (key === 'active' || key === 'active_foreground_only') && phase === 'active';

  const value = useMemo<LocationValue>(
    () => ({
      key,
      reading,
      settings,
      summary,
      mode,
      phase,
      sharing,
      enable,
      turnOnGps: async () => {
        await turnOnGps();
        await sync();
      },
      openSettings: openAppSettings,
      refresh: sync,
    }),
    [key, reading, settings, summary, mode, phase, sharing, enable, sync],
  );
  return <LocationContext.Provider value={value}>{children}</LocationContext.Provider>;
}

/** Igual que useWorkLocation pero sin lanzar fuera de la sesión (p. ej. en Screen). */
export function useOptionalWorkLocation(): LocationValue | null {
  return useContext(LocationContext);
}

export function useWorkLocation(): LocationValue {
  const ctx = useContext(LocationContext);
  if (!ctx) throw new Error('useWorkLocation debe usarse dentro de LocationProvider');
  return ctx;
}
