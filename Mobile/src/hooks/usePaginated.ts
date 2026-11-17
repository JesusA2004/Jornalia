import { useCallback, useEffect, useRef, useState } from 'react';

import type { ApiResult } from '@/api/client';
import { describeError } from '@/api/errors';
import type { PaginationMeta } from '@/types/contracts';

/** Lista paginada (PaginationMeta) con carga incremental. */
export function usePaginated<T>(fetchPage: (page: number) => Promise<ApiResult<T[], PaginationMeta>>, deps: readonly unknown[]) {
  const [items, setItems] = useState<T[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fetchRef = useRef(fetchPage);
  const seq = useRef(0);

  useEffect(() => {
    fetchRef.current = fetchPage;
  });

  const load = useCallback(async (page: number) => {
    const id = ++seq.current;
    try {
      const r = await fetchRef.current(page);
      if (id !== seq.current) return;
      setItems((prev) => (page === 1 ? r.data : [...prev, ...r.data]));
      setMeta(r.meta ?? null);
      setError(null);
    } catch (e) {
      if (id === seq.current) setError(describeError(e));
    } finally {
      if (id === seq.current) {
        setLoading(false);
        setLoadingMore(false);
        setRefreshing(false);
      }
    }
  }, []);

  useEffect(() => {
    void load(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [load, ...deps]);

  const hasMore = meta ? meta.current_page < meta.last_page : false;

  const loadMore = useCallback(() => {
    if (!meta || !hasMore || loadingMore || loading) return;
    setLoadingMore(true);
    void load(meta.current_page + 1);
  }, [meta, hasMore, loadingMore, loading, load]);

  const refresh = useCallback(async () => {
    setRefreshing(true);
    await load(1);
  }, [load]);

  return { items, meta, loading, loadingMore, refreshing, error, hasMore, loadMore, refresh };
}
