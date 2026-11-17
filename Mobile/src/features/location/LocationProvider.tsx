import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { api } from '@/services/container';
import { getDb } from '@/storage/db';
import type { LocationSettings } from '@/types/contracts';

import {
  isNotRequired,
  isRunning,
  type LocationReading,
  openAppSettings,
  readLocation,
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
import { locationSummary, type LocationSummary, type SqlExecutor, type TrackingKey, trackingKey, windowActive } from './workLocation';

interface LocationValue {
  key: TrackingKey;
  reading: LocationReading | null;
  settings: LocationSettings | null;
  summary: LocationSummary | null;
  /** Pide permiso de primer plano y, después, «todo el tiempo». */
  enable: () => Promise<void>;
  turnOnGps: () => Promise<void>;
  openSettings: () => Promise<void>;
  refresh: () => Promise<void>;
}

const LocationContext = createContext<LocationValue | null>(null);

const FOREGROUND_UPLOAD_MS = 30_000;

/**
 * Ubicación de trabajo de la sesión: arranca la tarea única al abrir la app
 * (Android 12+ sólo permite iniciarla en pantalla), alimenta la cola en primer
 * plano, envía cada 30 s y la detiene al cerrar sesión.
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
    if (r.foreground === 'granted') {
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
  });

  const value = useMemo<LocationValue>(
    () => ({
      key,
      reading,
      settings,
      summary,
      enable,
      turnOnGps: async () => {
        await turnOnGps();
        await sync();
      },
      openSettings: openAppSettings,
      refresh: sync,
    }),
    [key, reading, settings, summary, enable, sync],
  );
  return <LocationContext.Provider value={value}>{children}</LocationContext.Provider>;
}

export function useWorkLocation(): LocationValue {
  const ctx = useContext(LocationContext);
  if (!ctx) throw new Error('useWorkLocation debe usarse dentro de LocationProvider');
  return ctx;
}
