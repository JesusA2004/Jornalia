import { ApiRequestError, NetworkError, describeError } from '@/api/errors';
import type { SyncRequest, SyncResponse } from '@/types/contracts';

import type { PunchQueue } from './queue';

export interface SyncOutcome {
  sent: number;
  created: number;
  duplicate: number;
  rejected: number;
  failed: number;
  /** Motivo por el que se detuvo antes de vaciar la cola. */
  stoppedBy: 'network' | 'auth' | 'server' | null;
}

export interface SyncDeps {
  queue: PunchQueue;
  send: (body: SyncRequest) => Promise<SyncResponse>;
  /** Ignora el backoff (sincronización manual o al reconectar). */
  force?: boolean;
  maxBatches?: number;
}

const EMPTY: SyncOutcome = { sent: 0, created: 0, duplicate: 0, rejected: 0, failed: 0, stoppedBy: null };

/**
 * Envía la cola en lotes a POST /sync/punches y aplica resultados
 * created / duplicate / rejected. Sólo una sincronización corre a la vez.
 */
let running: Promise<SyncOutcome> | null = null;

export function syncPunches(deps: SyncDeps): Promise<SyncOutcome> {
  if (running) return running;
  running = doSync(deps).finally(() => {
    running = null;
  });
  return running;
}

export function isSyncRunning(): boolean {
  return running !== null;
}

async function doSync({ queue, send, force = false, maxBatches = 10 }: SyncDeps): Promise<SyncOutcome> {
  const outcome: SyncOutcome = { ...EMPTY };
  const seen = new Set<string>();
  for (let i = 0; i < maxBatches; i++) {
    const batch = (await queue.due(force)).filter((b) => !seen.has(b.client_uuid));
    if (!batch.length) break;
    batch.forEach((b) => seen.add(b.client_uuid));
    outcome.sent += batch.length;
    try {
      const res = await send({ punches: batch.map((b) => b.request) });
      const counts = await queue.applyResults(batch, res.results ?? []);
      outcome.created += counts.created;
      outcome.duplicate += counts.duplicate;
      outcome.rejected += counts.rejected;
      outcome.failed += counts.missing;
    } catch (e) {
      if (e instanceof ApiRequestError && (e.status === 401 || e.code.startsWith('device_'))) {
        // Sin sesión válida: no se penaliza el elemento; se reintentará tras iniciar sesión.
        outcome.sent -= batch.length;
        outcome.stoppedBy = 'auth';
        break;
      }
      await queue.markBatchFailed(batch, describeError(e));
      outcome.failed += batch.length;
      outcome.stoppedBy = e instanceof NetworkError ? 'network' : 'server';
      break;
    }
  }
  await queue.purgeSynced();
  return outcome;
}
