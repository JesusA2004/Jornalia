import { createContext, type ReactNode, useContext, useEffect, useMemo } from 'react';

import { useSync } from '@/features/offline/SyncProvider';

import { useQuery, type QueryState } from '@/hooks/useQuery';
import { api } from '@/services/container';
import type { TodayResponse } from '@/types/contracts';

interface TodayValue extends QueryState<TodayResponse> {
  /** Diferencia (ms) servidor − teléfono, para mostrar la hora oficial. */
  serverOffsetMs: number;
}

const TodayContext = createContext<TodayValue | null>(null);

/** GET /attendance/today compartido por Inicio y Asistencia (con caché offline). */
export function TodayProvider({ children }: { children: ReactNode }) {
  const q = useQuery(async () => {
    const sent = Date.now();
    const data = await api.attendance.today();
    const received = Date.now();
    return { data, offset: Date.parse(data.server_time) - (sent + received) / 2 };
  }, [], { cacheKey: 'today.v1' });

  // Tras sincronizar marcajes offline, actualizar la jornada.
  const { lastOutcome } = useSync();
  const refresh = q.refresh;
  useEffect(() => {
    if (!lastOutcome || lastOutcome.created + lastOutcome.duplicate + lastOutcome.rejected === 0) return;
    void Promise.resolve().then(refresh);
  }, [lastOutcome, refresh]);

  const value = useMemo<TodayValue>(
    () => ({
      ...q,
      data: q.data?.data ?? null,
      serverOffsetMs: q.data && !q.stale && Number.isFinite(q.data.offset) ? q.data.offset : 0,
    }),
    [q],
  );
  return <TodayContext.Provider value={value}>{children}</TodayContext.Provider>;
}

export function useToday(): TodayValue {
  const ctx = useContext(TodayContext);
  if (!ctx) throw new Error('useToday debe usarse dentro de TodayProvider');
  return ctx;
}
