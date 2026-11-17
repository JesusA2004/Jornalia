import NetInfo from '@react-native-community/netinfo';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { api, punchQueue } from '@/services/container';

import { registerBackgroundSync, unregisterBackgroundSync } from './backgroundSync';
import { type QueuedPunch, summarize, type QueueSummary } from './queue';
import { syncPunches, type SyncOutcome } from './sync';

interface SyncContextValue {
  isOnline: boolean;
  items: QueuedPunch[];
  summary: QueueSummary;
  syncing: boolean;
  lastSyncAt: number | null;
  lastOutcome: SyncOutcome | null;
  syncNow: (force?: boolean) => Promise<SyncOutcome | null>;
  reload: () => Promise<void>;
  dismissRejected: (clientUuid: string) => Promise<void>;
}

const SyncContext = createContext<SyncContextValue | null>(null);

/**
 * Estado de conectividad y de la cola offline. Sincroniza:
 *  - al iniciar sesión / abrir la app,
 *  - al recuperar conexión,
 *  - al volver a primer plano,
 *  - cuando vence el siguiente reintento (backoff),
 *  - y oportunistamente en segundo plano (expo-background-task).
 */
export function SyncProvider({ enabled, children }: { enabled: boolean; children: ReactNode }) {
  const [isOnline, setIsOnline] = useState(true);
  const [items, setItems] = useState<QueuedPunch[]>([]);
  const [syncing, setSyncing] = useState(false);
  const [lastSyncAt, setLastSyncAt] = useState<number | null>(null);
  const [lastOutcome, setLastOutcome] = useState<SyncOutcome | null>(null);
  const onlineRef = useRef(true);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const reload = useCallback(async () => {
    try {
      setItems(await punchQueue.list());
    } catch {
      // base local no disponible: se mantiene el último estado
    }
  }, []);

  const syncNow = useCallback(
    async (force = false) => {
      if (!enabled || !onlineRef.current) return null;
      setSyncing(true);
      try {
        const outcome = await syncPunches({ queue: punchQueue, send: api.sync.punches, force });
        setLastOutcome(outcome);
        if (outcome.sent > 0 || outcome.stoppedBy === null) setLastSyncAt(Date.now());
        return outcome;
      } catch {
        return null;
      } finally {
        setSyncing(false);
        await reload();
      }
    },
    [enabled, reload],
  );

  // Conectividad.
  useEffect(() => {
    const unsub = NetInfo.addEventListener((s) => {
      const online = s.isConnected === true && s.isInternetReachable !== false;
      const wasOnline = onlineRef.current;
      onlineRef.current = online;
      setIsOnline(online);
      if (online && !wasOnline) void syncNow(true);
    });
    return unsub;
  }, [syncNow]);

  // Inicio de sesión / apertura y regreso a primer plano.
  useEffect(() => {
    if (!enabled) {
      void unregisterBackgroundSync();
      return;
    }
    void punchQueue
      .list()
      .then((list) => {
        setItems(list);
        return syncNow(true);
      })
      .catch(() => undefined);
    void registerBackgroundSync();
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') void syncNow(false);
    });
    return () => sub.remove();
  }, [enabled, reload, syncNow]);

  const summary = useMemo(() => summarize(items), [items]);

  // Reintento programado según el backoff del elemento más próximo.
  useEffect(() => {
    if (timer.current) clearTimeout(timer.current);
    if (!enabled || summary.nextAttemptAt === null) return;
    const delay = Math.max(1000, summary.nextAttemptAt - Date.now());
    timer.current = setTimeout(() => void syncNow(false), Math.min(delay, 30 * 60_000));
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [enabled, summary.nextAttemptAt, syncNow]);

  const dismissRejected = useCallback(
    async (clientUuid: string) => {
      await punchQueue.dismissRejected(clientUuid);
      await reload();
    },
    [reload],
  );

  const value = useMemo<SyncContextValue>(
    () => ({ isOnline, items, summary, syncing, lastSyncAt, lastOutcome, syncNow, reload, dismissRejected }),
    [isOnline, items, summary, syncing, lastSyncAt, lastOutcome, syncNow, reload, dismissRejected],
  );
  return <SyncContext.Provider value={value}>{children}</SyncContext.Provider>;
}

export function useSync(): SyncContextValue {
  const ctx = useContext(SyncContext);
  if (!ctx) throw new Error('useSync debe usarse dentro de SyncProvider');
  return ctx;
}
