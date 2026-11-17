import { useCallback, useEffect, useRef, useState } from 'react';

import { describeError } from '@/api/errors';
import { cacheGet, cacheSet } from '@/storage/db';

export interface QueryState<T> {
  data: T | null;
  error: string | null;
  loading: boolean;
  refreshing: boolean;
  /** Los datos provienen de la caché local porque la red falló. */
  stale: boolean;
  staleAt: number | null;
  refresh: () => Promise<void>;
}

/**
 * Carga datos de la API con estados reales de carga/error/actualización.
 * Con `cacheKey`, guarda la última respuesta y la muestra (marcada como
 * desactualizada) si no hay conexión.
 */
export function useQuery<T>(fetcher: () => Promise<T>, deps: readonly unknown[], options: { cacheKey?: string; enabled?: boolean } = {}): QueryState<T> {
  const { cacheKey, enabled = true } = options;
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(enabled);
  const [refreshing, setRefreshing] = useState(false);
  const [staleAt, setStaleAt] = useState<number | null>(null);
  const fetcherRef = useRef(fetcher);
  const seq = useRef(0);

  useEffect(() => {
    fetcherRef.current = fetcher;
  });

  const run = useCallback(async () => {
    const id = ++seq.current;
    try {
      const result = await fetcherRef.current();
      if (id !== seq.current) return;
      setData(result);
      setError(null);
      setStaleAt(null);
      if (cacheKey) void cacheSet(cacheKey, result).catch(() => undefined);
    } catch (e) {
      if (id !== seq.current) return;
      const cached = cacheKey ? await cacheGet<T>(cacheKey).catch(() => null) : null;
      if (cached) {
        setData((prev) => prev ?? cached.value);
        setStaleAt(cached.updatedAt);
      }
      setError(describeError(e));
    } finally {
      if (id === seq.current) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }, [cacheKey]);

  useEffect(() => {
    if (!enabled) return;
    void run();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, run, ...deps]);

  const refresh = useCallback(async () => {
    setRefreshing(true);
    await run();
  }, [run]);

  return { data, error, loading, refreshing, stale: staleAt !== null, staleAt, refresh };
}
