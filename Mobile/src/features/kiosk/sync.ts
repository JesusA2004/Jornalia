import { ApiRequestError, describeError, NetworkError } from '@/api/errors';

import type { KioskQueue } from './queue';
import type { KioskPunchRequest, KioskSyncResponse } from './types';

export interface KioskSyncOutcome {
  sent: number;
  confirmed: number;
  rejected: number;
  failed: number;
  stoppedBy: 'network' | 'auth' | 'server' | null;
}

let running: Promise<KioskSyncOutcome> | null = null;

/** Envía la cola del kiosco a POST /kiosk/sync en lotes. Una sola ejecución a la vez. */
export function syncKiosk(queue: KioskQueue, send: (p: KioskPunchRequest[]) => Promise<KioskSyncResponse>, force = false): Promise<KioskSyncOutcome> {
  if (running) return running;
  running = run(queue, send, force).finally(() => {
    running = null;
  });
  return running;
}

async function run(queue: KioskQueue, send: (p: KioskPunchRequest[]) => Promise<KioskSyncResponse>, force: boolean): Promise<KioskSyncOutcome> {
  const out: KioskSyncOutcome = { sent: 0, confirmed: 0, rejected: 0, failed: 0, stoppedBy: null };
  const seen = new Set<string>();
  for (let i = 0; i < 10; i++) {
    const batch = (await queue.due(force)).filter((b) => !seen.has(b.client_uuid));
    if (!batch.length) break;
    batch.forEach((b) => seen.add(b.client_uuid));
    out.sent += batch.length;
    try {
      const res = await send(batch.map((b) => b.request));
      const c = await queue.applyResults(batch, res.results ?? []);
      out.confirmed += c.confirmed;
      out.rejected += c.rejected;
      out.failed += c.missing;
    } catch (e) {
      if (e instanceof ApiRequestError && (e.status === 401 || e.status === 403 || e.status === 410)) {
        // Kiosco desactivado: no se penalizan los marcajes; se conservan pendientes.
        out.sent -= batch.length;
        out.stoppedBy = 'auth';
        break;
      }
      await queue.markBatchFailed(batch, describeError(e));
      out.failed += batch.length;
      out.stoppedBy = e instanceof NetworkError ? 'network' : 'server';
      break;
    }
  }
  await queue.purge();
  return out;
}
