import NetInfo from '@react-native-community/netinfo';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { ApiRequestError } from '@/api/errors';
import { API_URL } from '@/config/env';
import { buildDeviceInfo } from '@/features/auth/deviceInfo';

import { createKioskClient } from './api';
import type { KioskOutcome } from './flow';
import { submitKioskPunch } from './punch';
import { KioskQueue, type KioskQueueSummary, summarizeKiosk } from './queue';
import { sqliteKioskStore } from './sqliteStore';
import { clearKioskSession, getKioskSession, saveExitHash, saveKioskSession, updateKioskInfo } from './storage';
import { syncKiosk } from './sync';
import type { KioskInfo, KioskPunchRequest } from './types';

export const kioskQueue = new KioskQueue(sqliteKioskStore);

/** Token del kiosco en memoria (fuente: SecureStore `kiosk.token`). */
let kioskToken: string | null = null;
let onRevoked: (() => void) | null = null;

export const kioskApi = createKioskClient({
  baseUrl: API_URL,
  getToken: () => kioskToken,
  onAuthFailure: (e) => {
    if (e.status === 401) onRevoked?.();
  },
});

type Status = 'loading' | 'inactive' | 'active';

interface KioskValue {
  status: Status;
  kiosk: KioskInfo | null;
  isOnline: boolean;
  serverOffsetMs: number;
  summary: KioskQueueSummary;
  activate: (code: string) => Promise<void>;
  /** Desactiva el kiosco local. Con `keepPending` conserva los marcajes sin enviar. */
  deactivate: (keepPending?: boolean) => Promise<void>;
  punch: (request: KioskPunchRequest) => Promise<KioskOutcome>;
  syncNow: (force?: boolean) => Promise<void>;
}

const KioskContext = createContext<KioskValue | null>(null);

const STATUS_EVERY_MS = 5 * 60_000;

/**
 * Modo kiosco de sucursal: dispositivo compartido autenticado con su propio
 * token. Comprueba su vigencia, sincroniza la cola offline (al reconectar, al
 * volver a primer plano y con temporizador) y nunca guarda datos de
 * colaboradores fuera de la cola cifrada.
 */
export function KioskProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [kiosk, setKiosk] = useState<KioskInfo | null>(null);
  const [isOnline, setOnline] = useState(true);
  const [offset, setOffset] = useState(0);
  const [summary, setSummary] = useState<KioskQueueSummary>({ pending: 0, rejected: 0, synced: 0, nextAttemptAt: null });
  const onlineRef = useRef(true);
  const active = status === 'active';

  const reload = useCallback(async () => {
    try {
      setSummary(summarizeKiosk(await kioskQueue.list()));
    } catch {
      // base no disponible
    }
  }, []);

  const localDeactivate = useCallback(async () => {
    kioskToken = null;
    await clearKioskSession();
    setKiosk(null);
    setStatus('inactive');
  }, []);

  useEffect(() => {
    onRevoked = () => void localDeactivate();
    return () => {
      onRevoked = null;
    };
  }, [localDeactivate]);

  // Restaurar.
  useEffect(() => {
    void getKioskSession().then((s) => {
      if (s) {
        kioskToken = s.token;
        setKiosk(s.kiosk);
        setStatus('active');
      } else setStatus('inactive');
    });
  }, []);

  const checkStatus = useCallback(async () => {
    if (!kioskToken) return;
    const sent = Date.now();
    try {
      const r = await kioskApi.status();
      const received = Date.now();
      const t = Date.parse(r.server_time);
      if (Number.isFinite(t)) setOffset(t - (sent + received) / 2);
      if (!r.active) {
        await localDeactivate();
        return;
      }
      setKiosk(r.kiosk);
      await updateKioskInfo(r.kiosk);
    } catch (e) {
      if (e instanceof ApiRequestError && (e.status === 401 || e.status === 403 || e.status === 410)) await localDeactivate();
    }
  }, [localDeactivate]);

  const syncNow = useCallback(
    async (force = false) => {
      if (!kioskToken || !onlineRef.current) return;
      try {
        const out = await syncKiosk(kioskQueue, kioskApi.sync, force);
        if (out.stoppedBy === 'auth') await checkStatus();
      } catch {
        // se reintenta después
      } finally {
        await reload();
      }
    },
    [checkStatus, reload],
  );

  useEffect(() => {
    return NetInfo.addEventListener((s) => {
      const online = s.isConnected === true && s.isInternetReachable !== false;
      const was = onlineRef.current;
      onlineRef.current = online;
      setOnline(online);
      if (online && !was) void syncNow(true);
    });
  }, [syncNow]);

  useEffect(() => {
    if (!active) return;
    void Promise.resolve()
      .then(checkStatus)
      .then(() => syncNow(true));
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') void checkStatus().then(() => syncNow(false));
    });
    const statusTimer = setInterval(() => void checkStatus(), STATUS_EVERY_MS);
    return () => {
      sub.remove();
      clearInterval(statusTimer);
    };
  }, [active, checkStatus, syncNow]);

  // Reintento según el backoff más próximo.
  useEffect(() => {
    if (!active || summary.nextAttemptAt === null) return;
    const delay = Math.min(30 * 60_000, Math.max(1000, summary.nextAttemptAt - Date.now()));
    const t = setTimeout(() => void syncNow(false), delay);
    return () => clearTimeout(t);
  }, [active, summary.nextAttemptAt, syncNow]);

  const activate = useCallback(async (code: string) => {
    const device = await buildDeviceInfo();
    const r = await kioskApi.activate({ activation_code: code.trim(), device });
    await saveKioskSession({ token: r.token, kiosk: r.kiosk });
    await saveExitHash(code).catch(() => undefined);
    kioskToken = r.token;
    setKiosk(r.kiosk);
    setStatus('active');
  }, []);

  const deactivate = useCallback(
    async (keepPending = true) => {
      await kioskQueue.purge(true).catch(() => undefined);
      if (!keepPending) {
        for (const i of await kioskQueue.list().catch(() => [])) await sqliteKioskStore.remove(i.client_uuid).catch(() => undefined);
      }
      await localDeactivate();
      await reload();
    },
    [localDeactivate, reload],
  );

  const punch = useCallback(
    async (request: KioskPunchRequest) => {
      try {
        return await submitKioskPunch(request, {
          isOnline: onlineRef.current,
          allowOffline: kiosk?.allow_offline !== false,
          send: kioskApi.punch,
          queue: kioskQueue,
        });
      } finally {
        void reload();
      }
    },
    [kiosk, reload],
  );

  const value = useMemo<KioskValue>(
    () => ({ status, kiosk, isOnline, serverOffsetMs: offset, summary, activate, deactivate, punch, syncNow }),
    [status, kiosk, isOnline, offset, summary, activate, deactivate, punch, syncNow],
  );
  return <KioskContext.Provider value={value}>{children}</KioskContext.Provider>;
}

export function useKiosk(): KioskValue {
  const ctx = useContext(KioskContext);
  if (!ctx) throw new Error('useKiosk debe usarse dentro de KioskProvider');
  return ctx;
}
