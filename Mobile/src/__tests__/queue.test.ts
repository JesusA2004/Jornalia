import { BACKOFF_BASE_MS, BACKOFF_MAX_MS, backoffDelay, PunchQueue, type QueuedPunch, type QueueStore, summarize } from '@/features/offline/queue';
import { syncPunches } from '@/features/offline/sync';
import { ApiRequestError, NetworkError } from '@/api/errors';
import type { Punch, PunchRequest, SyncRequest, SyncResponse } from '@/types/contracts';

class MemoryStore implements QueueStore {
  rows = new Map<string, QueuedPunch>();
  async insert(item: QueuedPunch) {
    if (!this.rows.has(item.client_uuid)) this.rows.set(item.client_uuid, { ...item });
  }
  async update(id: string, patch: Partial<QueuedPunch>) {
    const r = this.rows.get(id);
    if (r) this.rows.set(id, { ...r, ...patch });
  }
  async get(id: string) {
    return this.rows.get(id) ?? null;
  }
  async all() {
    return [...this.rows.values()].sort((a, b) => a.created_at - b.created_at);
  }
  async remove(id: string) {
    this.rows.delete(id);
  }
}

function req(id: string, type: PunchRequest['type'] = 'clock_in'): PunchRequest {
  return {
    client_uuid: id,
    type,
    device_time: '2026-11-17T15:00:00Z',
    timezone: 'America/Mexico_City',
    device_uuid: 'dev-1',
    auth_method: 'device_biometric',
    nonce: null,
    signature: 'sig',
    captured_offline: true,
    location: null,
    face_verification_id: null,
  };
}

function punch(uuid: string): Punch {
  return {
    uuid,
    type: 'clock_in',
    status: 'accepted',
    source: 'mobile_offline',
    server_time: '2026-11-17T15:05:00Z',
    device_time: '2026-11-17T15:00:00Z',
    clock_skew_seconds: 0,
    timezone: 'America/Mexico_City',
    auth_method: 'device_biometric',
    captured_offline: true,
    anomalies: [],
    location: null,
    hash: 'h',
    sequence: 1,
    rectified: false,
  };
}

describe('cola offline', () => {
  let now = 1_000_000;
  const clock = () => now;
  beforeEach(() => {
    now = 1_000_000;
  });

  it('backoff exponencial con tope y jitter', () => {
    expect(backoffDelay(1, () => 0)).toBe(BACKOFF_BASE_MS);
    expect(backoffDelay(2, () => 0)).toBe(BACKOFF_BASE_MS * 2);
    expect(backoffDelay(50, () => 0)).toBe(BACKOFF_MAX_MS);
    expect(backoffDelay(1, () => 1)).toBe(Math.round(BACKOFF_BASE_MS * 1.2));
  });

  it('encolar es idempotente por client_uuid', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, clock);
    await q.enqueue(req('a'));
    await q.enqueue(req('a'));
    expect((await q.list()).length).toBe(1);
  });

  it('aplica created / duplicate / rejected y reintenta los no informados', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, clock, () => 0);
    for (const id of ['a', 'b', 'c', 'd']) {
      await q.enqueue(req(id));
      now += 1;
    }
    const batch = await q.due();
    const counts = await q.applyResults(batch, [
      { client_uuid: 'a', result: 'created', punch: punch('p-a') },
      { client_uuid: 'b', result: 'duplicate', punch: punch('p-b') },
      { client_uuid: 'c', result: 'rejected', error: { code: 'invalid_signature', message: 'Firma inválida' } },
    ]);
    expect(counts).toEqual({ created: 1, duplicate: 1, rejected: 1, missing: 1 });
    expect((await store.get('a'))?.status).toBe('synced');
    expect((await store.get('b'))?.server_punch?.uuid).toBe('p-b');
    expect((await store.get('c'))?.status).toBe('rejected');
    const d = await store.get('d');
    expect(d?.status).toBe('pending');
    expect(d?.attempts).toBe(1);
    expect(d!.next_attempt_at).toBe(now + BACKOFF_BASE_MS);
    expect(summarize(await q.list())).toMatchObject({ pending: 1, rejected: 1, synced: 2 });
  });

  it('sólo descarta rechazados, nunca pendientes', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, clock);
    await q.enqueue(req('a'));
    await q.dismissRejected('a');
    expect(await store.get('a')).not.toBeNull();
  });

  it('respeta el backoff salvo sincronización forzada', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, clock, () => 0);
    await q.enqueue(req('a'));
    await q.markBatchFailed(await q.due(), 'sin red');
    expect(await q.due()).toHaveLength(0);
    expect(await q.due(true)).toHaveLength(1);
    now += BACKOFF_BASE_MS;
    expect(await q.due()).toHaveLength(1);
  });

  it('purga sincronizados antiguos', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, clock);
    await q.enqueue(req('a'));
    await q.applyResults(await q.due(), [{ client_uuid: 'a', result: 'created', punch: punch('p') }]);
    now += 8 * 24 * 3600_000;
    await q.purgeSynced();
    expect(await store.get('a')).toBeNull();
  });
});

describe('sincronización POST /sync/punches', () => {
  it('envía en lote y marca resultados', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, () => 1);
    await q.enqueue(req('a'));
    const send = jest.fn(async (b: SyncRequest): Promise<SyncResponse> => ({
      results: b.punches.map((p) => ({ client_uuid: p.client_uuid, result: 'created' as const, punch: punch(`s-${p.client_uuid}`) })),
    }));
    const out = await syncPunches({ queue: q, send, force: true });
    expect(send).toHaveBeenCalledTimes(1);
    expect(out).toMatchObject({ sent: 1, created: 1, stoppedBy: null });
  });

  it('sin red: conserva y programa reintento', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, () => 1, () => 0);
    await q.enqueue(req('a'));
    const out = await syncPunches({ queue: q, send: async () => Promise.reject(new NetworkError('x')), force: true });
    expect(out.stoppedBy).toBe('network');
    expect((await store.get('a'))?.attempts).toBe(1);
  });

  it('401: se detiene sin penalizar el elemento', async () => {
    const store = new MemoryStore();
    const q = new PunchQueue(store, () => 1);
    await q.enqueue(req('a'));
    const out = await syncPunches({
      queue: q,
      send: async () => Promise.reject(new ApiRequestError(401, { code: 'unauthenticated', message: 'x' })),
      force: true,
    });
    expect(out.stoppedBy).toBe('auth');
    expect((await store.get('a'))?.attempts).toBe(0);
  });
});
