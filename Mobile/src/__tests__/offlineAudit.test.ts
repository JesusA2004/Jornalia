import { displayState, PunchQueue, type QueuedPunch, type QueueStore, summarize } from '@/features/offline/queue';
import { syncPunches } from '@/features/offline/sync';
import { buildReceipts, type LocalReceipt } from '@/features/notifications/receipts';
import { isPunchReceipt, needsReadConfirmation, routeForNotification } from '@/features/notifications/routing';
import type { AppNotification, Punch, PunchRequest, SyncRequest, SyncResponse } from '@/types/contracts';

jest.mock('@/storage/db', () => ({ cacheGet: jest.fn(), cacheSet: jest.fn() }));

/** Simula SQLite persistente: sobrevive a "reinicios" (nueva PunchQueue sobre el mismo almacén). */
class DiskStore implements QueueStore {
  rows = new Map<string, QueuedPunch>();
  async insert(item: QueuedPunch) {
    if (!this.rows.has(item.client_uuid)) this.rows.set(item.client_uuid, structuredClone(item));
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

const req = (id: string, type: PunchRequest['type'], deviceTime: string): PunchRequest => ({
  client_uuid: id,
  type,
  device_time: deviceTime,
  timezone: 'America/Mexico_City',
  device_uuid: 'dev-1',
  auth_method: 'device_biometric',
  nonce: null,
  signature: 'sig',
  captured_offline: true,
  location: null,
  face_verification_id: null,
});

const punch = (uuid: string, status: Punch['status'] = 'accepted', type: Punch['type'] = 'clock_in'): Punch => ({
  uuid,
  type,
  status,
  source: 'mobile_offline',
  server_time: '2026-11-17T18:00:00Z',
  received_at: '2026-11-17T18:00:00Z',
  device_time: '2026-11-17T15:00:00Z',
  clock_skew_seconds: 0,
  timezone: 'America/Mexico_City',
  auth_method: 'device_biometric',
  captured_offline: true,
  anomalies: status === 'flagged' ? ['late_sync'] : [],
  location: null,
  hash: 'h',
  sequence: 1,
  rectified: false,
});

describe('cola offline: auditoría', () => {
  it('envía en orden de captura, conserva device_time y declara synced_at', async () => {
    let t = 1000;
    const store = new DiskStore();
    const q = new PunchQueue(store, () => t++);
    await q.enqueue(req('a', 'clock_in', '2026-11-17T15:00:00Z'));
    await q.enqueue(req('b', 'break_start', '2026-11-17T19:00:00Z'));
    await q.enqueue(req('a', 'clock_in', '2026-11-17T15:00:00Z')); // duplicado local ignorado
    const sent: SyncRequest[] = [];
    const send = async (body: SyncRequest): Promise<SyncResponse> => {
      sent.push(body);
      return { results: body.punches.map((p) => ({ client_uuid: p.client_uuid, result: 'created' as const, punch: punch(p.client_uuid) })) };
    };
    await syncPunches({ queue: q, send, now: () => new Date('2026-11-17T18:00:00.500Z') });
    expect(sent).toHaveLength(1);
    expect(sent[0]!.punches.map((p) => p.client_uuid)).toEqual(['a', 'b']);
    expect(sent[0]!.punches[0]!.device_time).toBe('2026-11-17T15:00:00Z');
    expect(sent[0]!.synced_at).toBe('2026-11-17T18:00:00Z');
    expect(sent[0]!.punches[0]!.synced_at).toBe('2026-11-17T18:00:00Z');
    // La firma no cambia: synced_at no forma parte de la cadena canónica.
    expect(sent[0]!.punches[0]!.signature).toBe('sig');
  });

  it('recupera la cola tras un cierre inesperado durante el envío (sin duplicar en servidor)', async () => {
    const store = new DiskStore();
    const q1 = new PunchQueue(store, () => 0);
    await q1.enqueue(req('x', 'clock_in', '2026-11-17T15:00:00Z'));
    // El envío falla a medias (la app se cierra sin respuesta): no se pierde ni se marca como enviado.
    await syncPunches({ queue: q1, send: async () => Promise.reject(new Error('cierre inesperado')), force: true });
    expect((await q1.list())[0]!.status).toBe('pending');
    // Reinicio de la app: nueva cola sobre el mismo almacén persistente.
    const q2 = new PunchQueue(store, () => 0);
    expect((await q2.due(true)).map((i) => i.client_uuid)).toEqual(['x']);
    // El servidor ya lo tenía: responde «duplicate» y queda sincronizado una sola vez.
    await syncPunches({ queue: q2, send: async () => ({ results: [{ client_uuid: 'x', result: 'duplicate', punch: punch('srv-x') }] }), force: true });
    const items = await q2.list();
    expect(items).toHaveLength(1);
    expect(items[0]!.status).toBe('synced');
  });

  it('estados visibles: pendiente nunca es «confirmado»; aceptado con anomalías = en revisión', async () => {
    const store = new DiskStore();
    const q = new PunchQueue(store, () => 0);
    for (const id of ['p', 'ok', 'flag', 'bad']) await q.enqueue(req(id, 'clock_in', '2026-11-17T15:00:00Z'));
    await q.applyResults(await q.due(true), [
      { client_uuid: 'ok', result: 'created', punch: punch('s-ok') },
      { client_uuid: 'flag', result: 'created', punch: punch('s-flag', 'flagged') },
      { client_uuid: 'bad', result: 'rejected', error: { code: 'invalid_sequence', message: 'Secuencia inválida' } },
    ]);
    const items = await q.list();
    const state = (id: string) => displayState(items.find((i) => i.client_uuid === id)!);
    expect(state('p')).toBe('pending'); // sin respuesta del servidor
    expect(state('ok')).toBe('confirmed');
    expect(state('flag')).toBe('review');
    expect(state('bad')).toBe('rejected');
    expect(summarize(items)).toMatchObject({ pending: 1, synced: 2, review: 1, rejected: 1 });
  });
});

describe('acuses en la bandeja de la app', () => {
  const notif = (o: Partial<AppNotification>): AppNotification => ({
    id: 'n-1',
    kind: 'attendance.punch_receipt',
    category: 'attendance',
    priority: 'normal',
    title: 'Acuse de entrada',
    body: 'Entrada registrada',
    data: { punch_uuid: 's-1', type: 'clock_in', receipt_code: 'F-1' },
    read_at: null,
    created_at: '2026-11-17T15:00:01Z',
    ...o,
  });

  it('une servidor, comprobantes locales y cola sin duplicar; lo pendiente no se confirma', async () => {
    const store = new DiskStore();
    const q = new PunchQueue(store, () => 0);
    await q.enqueue(req('c-pend', 'clock_out', '2026-11-17T23:00:00Z'));
    const local: LocalReceipt[] = [
      { punch_uuid: 's-1', type: 'clock_in', server_time: '2026-11-17T15:00:00Z', device_time: '2026-11-17T15:00:00Z', status: 'accepted', receipt_code: 'F-1' },
    ];
    const entries = buildReceipts(local, await q.list(), [notif({})]);
    expect(entries).toHaveLength(2);
    expect(entries[0]).toMatchObject({ key: 'c-pend', state: 'pending', serverTime: null });
    expect(entries[1]).toMatchObject({ key: 's-1', state: 'confirmed', notificationId: 'n-1', read: false });
  });

  it('el acuse aparece aunque el servidor no mande aviso (sin push)', () => {
    const local: LocalReceipt[] = [
      { punch_uuid: 's-9', type: 'break_start', server_time: '2026-11-17T19:00:00Z', device_time: null, status: 'flagged', receipt_code: null },
    ];
    expect(buildReceipts(local, [], [])).toEqual([expect.objectContaining({ key: 's-9', state: 'review', type: 'break_start' })]);
  });

  it('clasifica acuses y comunicados con confirmación de lectura', () => {
    expect(isPunchReceipt(notif({}))).toBe(true);
    expect(isPunchReceipt(notif({ kind: 'announcement', category: 'message', data: {} }))).toBe(false);
    expect(needsReadConfirmation(notif({ kind: 'announcement', data: { announcement_id: 'u', requires_confirmation: '1' } }))).toBe(true);
    expect(needsReadConfirmation(notif({ kind: 'announcement', data: { announcement_id: 'u' } }))).toBe(false);
  });

  it('navega desde la notificación a la solicitud correspondiente', () => {
    expect(routeForNotification('rectification.to_sign', { subject: 'rectification', subject_id: '42' })).toEqual({
      pathname: '/rectificaciones/[id]',
      params: { id: '42' },
    });
    expect(routeForNotification('attendance.punch_receipt', { punch_uuid: '3f2c1a9e-0b7d-4c5e-9a1f-2b3c4d5e6f70' })).toEqual({
      pathname: '/comprobante/[uuid]',
      params: { uuid: '3f2c1a9e-0b7d-4c5e-9a1f-2b3c4d5e6f70' },
    });
    expect(routeForNotification('device.revoked', { device_uuid: 'd' })).toEqual({ pathname: '/dispositivos' });
    expect(routeForNotification('announcement', { notification_id: 'n-7', announcement_id: 'a' })).toEqual({
      pathname: '/notificaciones',
      params: { focus: 'n-7' },
    });
  });
});
