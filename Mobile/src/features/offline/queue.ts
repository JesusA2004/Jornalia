import type { ApiError, Punch, PunchRequest, SyncItemResult } from '@/types/contracts';

/**
 * Cola persistente de marcajes capturados sin conexión.
 *
 * Cada elemento conserva el `PunchRequest` ya firmado al momento de la captura
 * (el secreto del dispositivo no se vuelve a leer al sincronizar). La
 * idempotencia la garantiza `client_uuid`: el servidor responde `duplicate` si
 * ya lo había recibido.
 */

export type QueueStatus = 'pending' | 'synced' | 'rejected';

export interface QueuedPunch {
  client_uuid: string;
  request: PunchRequest;
  status: QueueStatus;
  attempts: number;
  /** Epoch ms del siguiente intento permitido. */
  next_attempt_at: number;
  last_error: string | null;
  error: ApiError | null;
  created_at: number;
  synced_at: number | null;
  /** Marcaje sellado por el servidor (created/duplicate). */
  server_punch: Punch | null;
}

export interface QueueStore {
  insert(item: QueuedPunch): Promise<void>;
  update(clientUuid: string, patch: Partial<Omit<QueuedPunch, 'client_uuid' | 'request' | 'created_at'>>): Promise<void>;
  get(clientUuid: string): Promise<QueuedPunch | null>;
  /** Todos los elementos, del más antiguo al más reciente. */
  all(): Promise<QueuedPunch[]>;
  remove(clientUuid: string): Promise<void>;
}

export const BACKOFF_BASE_MS = 15_000;
export const BACKOFF_MAX_MS = 30 * 60_000;
export const SYNC_BATCH_SIZE = 50;
/** Los sincronizados se conservan este tiempo para mostrarlos en la app. */
export const SYNCED_RETENTION_MS = 7 * 24 * 3600_000;

/**
 * Retraso exponencial con jitter: base·2^(intentos-1), tope BACKOFF_MAX_MS,
 * más hasta 20 % aleatorio para evitar ráfagas simultáneas.
 */
export function backoffDelay(attempts: number, random: () => number = Math.random): number {
  const exp = Math.min(BACKOFF_MAX_MS, BACKOFF_BASE_MS * 2 ** Math.max(0, attempts - 1));
  return Math.round(exp + exp * 0.2 * random());
}

/**
 * Estado visible de un marcaje de la cola. Sólo «confirmed»/«review» significan
 * que el servidor lo recibió y selló; «pending» nunca se muestra como registrado.
 *  - review: aceptado con anomalías (p. ej. sincronización tardía) → RH lo revisa.
 *  - rejected: el servidor no lo aceptó → requiere revisión del colaborador.
 */
export type DisplayState = 'pending' | 'confirmed' | 'review' | 'rejected';

export function displayState(item: QueuedPunch): DisplayState {
  if (item.status === 'pending') return 'pending';
  if (item.status === 'rejected') return 'rejected';
  return item.server_punch?.status === 'flagged' ? 'review' : 'confirmed';
}

export interface QueueSummary {
  pending: number;
  rejected: number;
  synced: number;
  /** Sincronizados que el servidor aceptó con observaciones. */
  review: number;
  oldestPendingAt: number | null;
  nextAttemptAt: number | null;
}

export function summarize(items: QueuedPunch[]): QueueSummary {
  let pending = 0;
  let rejected = 0;
  let synced = 0;
  let review = 0;
  let oldestPendingAt: number | null = null;
  let nextAttemptAt: number | null = null;
  for (const it of items) {
    if (it.status === 'pending') {
      pending++;
      oldestPendingAt = oldestPendingAt === null ? it.created_at : Math.min(oldestPendingAt, it.created_at);
      nextAttemptAt = nextAttemptAt === null ? it.next_attempt_at : Math.min(nextAttemptAt, it.next_attempt_at);
    } else if (it.status === 'rejected') rejected++;
    else {
      synced++;
      if (it.server_punch?.status === 'flagged') review++;
    }
  }
  return { pending, rejected, synced, review, oldestPendingAt, nextAttemptAt };
}

export class PunchQueue {
  constructor(
    private readonly store: QueueStore,
    private readonly now: () => number = Date.now,
    private readonly random: () => number = Math.random,
  ) {}

  async enqueue(request: PunchRequest): Promise<QueuedPunch> {
    const existing = await this.store.get(request.client_uuid);
    if (existing) return existing; // idempotente
    const item: QueuedPunch = {
      client_uuid: request.client_uuid,
      request,
      status: 'pending',
      attempts: 0,
      next_attempt_at: this.now(),
      last_error: null,
      error: null,
      created_at: this.now(),
      synced_at: null,
      server_punch: null,
    };
    await this.store.insert(item);
    return item;
  }

  async list(): Promise<QueuedPunch[]> {
    return this.store.all();
  }

  /** Pendientes cuyo siguiente intento ya venció (o todos si `force`). */
  async due(force = false, limit = SYNC_BATCH_SIZE): Promise<QueuedPunch[]> {
    const t = this.now();
    const all = await this.store.all();
    return all.filter((i) => i.status === 'pending' && (force || i.next_attempt_at <= t)).slice(0, limit);
  }

  /** Aplica la respuesta de POST /sync/punches a un lote enviado. */
  async applyResults(batch: QueuedPunch[], results: SyncItemResult[]): Promise<{ created: number; duplicate: number; rejected: number; missing: number }> {
    const byUuid = new Map(results.map((r) => [r.client_uuid, r]));
    const counts = { created: 0, duplicate: 0, rejected: 0, missing: 0 };
    const t = this.now();
    for (const item of batch) {
      const r = byUuid.get(item.client_uuid);
      if (!r) {
        // El servidor no informó este elemento: se reintenta más tarde.
        counts.missing++;
        await this.scheduleRetry(item, 'El servidor no confirmó este marcaje.');
        continue;
      }
      if (r.result === 'created' || r.result === 'duplicate') {
        counts[r.result]++;
        await this.store.update(item.client_uuid, {
          status: 'synced',
          synced_at: t,
          server_punch: r.punch,
          last_error: null,
          error: null,
          attempts: item.attempts + 1,
        });
      } else {
        counts.rejected++;
        await this.store.update(item.client_uuid, {
          status: 'rejected',
          error: r.error,
          last_error: r.error.message,
          attempts: item.attempts + 1,
        });
      }
    }
    return counts;
  }

  /** Falla de red o del servidor para todo el lote: reintento con backoff. */
  async markBatchFailed(batch: QueuedPunch[], message: string): Promise<void> {
    for (const item of batch) await this.scheduleRetry(item, message);
  }

  /** Elimina un rechazado ya revisado por el usuario. Los pendientes no se pueden descartar. */
  async dismissRejected(clientUuid: string): Promise<void> {
    const item = await this.store.get(clientUuid);
    if (item?.status === 'rejected') await this.store.remove(clientUuid);
  }

  async purgeSynced(): Promise<void> {
    const limit = this.now() - SYNCED_RETENTION_MS;
    for (const item of await this.store.all()) {
      if (item.status === 'synced' && (item.synced_at ?? 0) < limit) await this.store.remove(item.client_uuid);
    }
  }

  private async scheduleRetry(item: QueuedPunch, message: string): Promise<void> {
    const attempts = item.attempts + 1;
    await this.store.update(item.client_uuid, {
      attempts,
      last_error: message,
      next_attempt_at: this.now() + backoffDelay(attempts, this.random),
    });
  }
}
