import { backoffDelay, SYNC_BATCH_SIZE } from '@/features/offline/queue';
import type { ApiError } from '@/types/contracts';

import type { KioskPunchRequest, KioskSyncItemResult } from './types';

/**
 * Cola offline del kiosco (lógica pura; el almacén se inyecta).
 *
 * Riesgo documentado: para que el servidor valide al colaborador, el marcaje
 * en cola conserva el NIP. Se guarda SOLO en la base local cifrada (SQLCipher,
 * clave en Keystore/Keychain) y se vacía en cuanto el servidor confirma o
 * rechaza el marcaje. Un marcaje pendiente no se puede descartar sin aviso.
 */
export type KioskQueueStatus = 'pending' | 'synced' | 'rejected';

export interface KioskQueued {
  client_uuid: string;
  request: KioskPunchRequest;
  status: KioskQueueStatus;
  attempts: number;
  next_attempt_at: number;
  last_error: string | null;
  error: ApiError | null;
  created_at: number;
  synced_at: number | null;
}

export interface KioskQueueStore {
  insert(item: KioskQueued): Promise<void>;
  update(clientUuid: string, patch: Partial<Omit<KioskQueued, 'client_uuid' | 'created_at'>>): Promise<void>;
  get(clientUuid: string): Promise<KioskQueued | null>;
  /** Del más antiguo al más reciente. */
  all(): Promise<KioskQueued[]>;
  remove(clientUuid: string): Promise<void>;
}

/** Los confirmados se conservan 24 h (sólo conteo; no se muestran a terceros). */
export const KIOSK_SYNCED_RETENTION_MS = 24 * 3600_000;

export interface KioskQueueSummary {
  pending: number;
  rejected: number;
  synced: number;
  nextAttemptAt: number | null;
}

export function summarizeKiosk(items: KioskQueued[]): KioskQueueSummary {
  const s: KioskQueueSummary = { pending: 0, rejected: 0, synced: 0, nextAttemptAt: null };
  for (const i of items) {
    if (i.status === 'pending') {
      s.pending++;
      s.nextAttemptAt = s.nextAttemptAt === null ? i.next_attempt_at : Math.min(s.nextAttemptAt, i.next_attempt_at);
    } else if (i.status === 'rejected') s.rejected++;
    else s.synced++;
  }
  return s;
}

/** Quita el NIP del registro guardado. */
const withoutPin = (r: KioskPunchRequest): KioskPunchRequest => ({ ...r, pin: '' });

export class KioskQueue {
  constructor(
    private readonly store: KioskQueueStore,
    private readonly now: () => number = Date.now,
    private readonly random: () => number = Math.random,
  ) {}

  /** Idempotente por client_uuid. */
  async enqueue(request: KioskPunchRequest): Promise<KioskQueued> {
    const existing = await this.store.get(request.client_uuid);
    if (existing) return existing;
    const item: KioskQueued = {
      client_uuid: request.client_uuid,
      request: { ...request, captured_offline: true },
      status: 'pending',
      attempts: 0,
      next_attempt_at: this.now(),
      last_error: null,
      error: null,
      created_at: this.now(),
      synced_at: null,
    };
    await this.store.insert(item);
    return item;
  }

  list(): Promise<KioskQueued[]> {
    return this.store.all();
  }

  /** Pendientes vencidos, en orden de captura. */
  async due(force = false, limit = SYNC_BATCH_SIZE): Promise<KioskQueued[]> {
    const t = this.now();
    return (await this.store.all()).filter((i) => i.status === 'pending' && (force || i.next_attempt_at <= t)).slice(0, limit);
  }

  async applyResults(batch: KioskQueued[], results: KioskSyncItemResult[]): Promise<{ confirmed: number; rejected: number; missing: number }> {
    const byUuid = new Map(results.map((r) => [r.client_uuid, r]));
    const counts = { confirmed: 0, rejected: 0, missing: 0 };
    for (const item of batch) {
      const r = byUuid.get(item.client_uuid);
      if (!r) {
        counts.missing++;
        await this.retry(item, 'El servidor no confirmó este marcaje.');
      } else if (r.result !== 'rejected') {
        counts.confirmed++;
        await this.store.update(item.client_uuid, {
          status: 'synced',
          synced_at: this.now(),
          request: withoutPin(item.request),
          attempts: item.attempts + 1,
          last_error: null,
          error: null,
        });
      } else {
        counts.rejected++;
        await this.store.update(item.client_uuid, {
          status: 'rejected',
          request: withoutPin(item.request),
          attempts: item.attempts + 1,
          error: r.error ?? null,
          last_error: r.error?.message ?? 'Rechazado por el servidor.',
        });
      }
    }
    return counts;
  }

  async markBatchFailed(batch: KioskQueued[], message: string): Promise<void> {
    for (const item of batch) await this.retry(item, message);
  }

  /** Elimina confirmados antiguos (y, si `all`, todos los confirmados y rechazados). */
  async purge(all = false): Promise<void> {
    const limit = this.now() - KIOSK_SYNCED_RETENTION_MS;
    for (const i of await this.store.all()) {
      if (i.status === 'pending') continue;
      if (all || (i.synced_at ?? i.created_at) < limit) await this.store.remove(i.client_uuid);
    }
  }

  private async retry(item: KioskQueued, message: string): Promise<void> {
    const attempts = item.attempts + 1;
    await this.store.update(item.client_uuid, {
      attempts,
      last_error: message,
      next_attempt_at: this.now() + backoffDelay(attempts, this.random),
    });
  }
}
