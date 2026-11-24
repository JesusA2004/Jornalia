import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { ApiRequestError, NetworkError } from '@/api/errors';
import { api } from '@/services/container';
import { cacheGet, cacheSet } from '@/storage/db';
import type { PendingDocument } from '@/types/contracts';

import { decideGate, type FetchOutcome, type GateDecision } from './gate';

const CACHE_KEY = 'documents.pending';

interface DocumentsValue extends GateDecision {
  loading: boolean;
  refresh: () => Promise<void>;
  /** Quita de la lista un documento ya firmado/aceptado. */
  markSigned: (id: number) => void;
}

const DocumentsContext = createContext<DocumentsValue | null>(null);

async function fetchOutcome(): Promise<FetchOutcome> {
  try {
    return { kind: 'ok', docs: await api.documents.pending() };
  } catch (e) {
    if (e instanceof ApiRequestError && (e.status === 404 || e.status === 405)) return { kind: 'unsupported' };
    if (e instanceof NetworkError || (e instanceof ApiRequestError && e.isRetriable)) return { kind: 'network' };
    return { kind: 'error' };
  }
}

/** Consulta el servidor (o la caché sin conexión) y decide el bloqueo. */
async function loadDecision(): Promise<GateDecision> {
  const outcome = await fetchOutcome();
  if (outcome.kind === 'ok') await cacheSet(CACHE_KEY, outcome.docs).catch(() => undefined);
  const cached = outcome.kind === 'ok' ? null : ((await cacheGet<PendingDocument[]>(CACHE_KEY).catch(() => null))?.value ?? null);
  return decideGate(outcome, cached);
}

/** Estado del convenio/aviso pendiente para bloquear el marcaje. */
export function DocumentsProvider({ userId, children }: { userId: number; children: ReactNode }) {
  const [decision, setDecision] = useState<GateDecision>({ blocked: false, docs: [], fromCache: false });
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    setDecision(await loadDecision());
    setLoading(false);
  }, []);

  useEffect(() => {
    let alive = true;
    void loadDecision().then((d) => {
      if (!alive) return;
      setDecision(d);
      setLoading(false);
    });
    return () => {
      alive = false;
    };
  }, [userId]);

  const markSigned = useCallback((id: number) => {
    setDecision((d) => {
      const docs = d.docs.filter((x) => x.id !== id);
      void cacheSet(CACHE_KEY, docs).catch(() => undefined);
      return { ...d, docs, blocked: docs.length > 0 };
    });
  }, []);

  const value = useMemo<DocumentsValue>(() => ({ ...decision, loading, refresh, markSigned }), [decision, loading, refresh, markSigned]);
  return <DocumentsContext.Provider value={value}>{children}</DocumentsContext.Provider>;
}

export function useDocuments(): DocumentsValue {
  const ctx = useContext(DocumentsContext);
  if (!ctx) throw new Error('useDocuments debe usarse dentro de DocumentsProvider');
  return ctx;
}
