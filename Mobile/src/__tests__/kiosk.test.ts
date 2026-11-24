import { ApiRequestError, NetworkError } from '@/api/errors';
import { BACKOFF_BASE_MS } from '@/features/offline/queue';
import { createKioskClient } from '@/features/kiosk/api';
import { autoResetMs, DONE_TIMEOUT_MS, IDLE_TIMEOUT_MS, initialKioskFlow, type KioskAction, type KioskFlow, kioskReducer } from '@/features/kiosk/flow';
import { KioskPunchError, submitKioskPunch, toKioskError } from '@/features/kiosk/punch';
import { KioskQueue, type KioskQueued, type KioskQueueStore, summarizeKiosk } from '@/features/kiosk/queue';
import { activationHash } from '@/features/kiosk/storage';
import { syncKiosk } from '@/features/kiosk/sync';
import type { KioskPunchRequest } from '@/features/kiosk/types';

jest.mock('expo-secure-store', () => ({ getItemAsync: jest.fn(), setItemAsync: jest.fn(), deleteItemAsync: jest.fn() }));

class MemoryStore implements KioskQueueStore {
  rows = new Map<string, KioskQueued>();
  async insert(i: KioskQueued) {
    if (!this.rows.has(i.client_uuid)) this.rows.set(i.client_uuid, { ...i });
  }
  async update(id: string, patch: Partial<KioskQueued>) {
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

const req = (id: string, type: KioskPunchRequest['type'] = 'clock_in'): KioskPunchRequest => ({
  employee_number: '1024',
  pin: '4321',
  type,
  client_uuid: id,
  device_time: '2026-11-23T15:00:00Z',
  timezone: 'America/Mexico_City',
  location: null,
  captured_offline: false,
  face_verification_id: null,
});

function setup() {
  let t = 1_000_000;
  const store = new MemoryStore();
  const queue = new KioskQueue(store, () => t, () => 0);
  return { store, queue, advance: (ms: number) => (t += ms), now: () => t };
}

describe('cola del kiosco', () => {
  it('es idempotente por client_uuid y marca captured_offline', async () => {
    const { queue } = setup();
    await queue.enqueue(req('a'));
    await queue.enqueue({ ...req('a'), type: 'clock_out' });
    const all = await queue.list();
    expect(all).toHaveLength(1);
    expect(all[0]!.request.type).toBe('clock_in');
    expect(all[0]!.request.captured_offline).toBe(true);
  });

  it('conserva el orden de captura', async () => {
    const { queue, advance } = setup();
    await queue.enqueue(req('a'));
    advance(10);
    await queue.enqueue(req('b', 'clock_out'));
    expect((await queue.due()).map((i) => i.client_uuid)).toEqual(['a', 'b']);
  });

  it('aplica created/duplicate/rejected y borra el NIP', async () => {
    const { queue, store } = setup();
    await queue.enqueue(req('a'));
    await queue.enqueue(req('b'));
    await queue.enqueue(req('c'));
    const batch = await queue.due();
    const counts = await queue.applyResults(batch, [
      { client_uuid: 'a', result: 'created' },
      { client_uuid: 'b', result: 'duplicate' },
      { client_uuid: 'c', result: 'rejected', error: { code: 'invalid_sequence', message: 'Secuencia' } },
    ]);
    expect(counts).toEqual({ confirmed: 2, rejected: 1, missing: 0 });
    for (const id of ['a', 'b', 'c']) expect(store.rows.get(id)!.request.pin).toBe('');
    expect(store.rows.get('a')!.status).toBe('synced');
    expect(store.rows.get('c')!.status).toBe('rejected');
    expect(summarizeKiosk(await queue.list())).toMatchObject({ pending: 0, synced: 2, rejected: 1 });
  });

  it('reintenta con backoff y conserva el NIP mientras esté pendiente', async () => {
    const { queue, store, now, advance } = setup();
    await queue.enqueue(req('a'));
    await queue.markBatchFailed(await queue.due(), 'sin red');
    const item = store.rows.get('a')!;
    expect(item.attempts).toBe(1);
    expect(item.next_attempt_at).toBe(now() + BACKOFF_BASE_MS);
    expect(item.request.pin).toBe('4321');
    expect(await queue.due()).toHaveLength(0);
    expect(await queue.due(true)).toHaveLength(1);
    advance(BACKOFF_BASE_MS);
    expect(await queue.due()).toHaveLength(1);
  });

  it('un elemento no informado por el servidor se reintenta', async () => {
    const { queue, store } = setup();
    await queue.enqueue(req('a'));
    const c = await queue.applyResults(await queue.due(), []);
    expect(c.missing).toBe(1);
    expect(store.rows.get('a')!.status).toBe('pending');
  });

  it('purge(all) nunca elimina pendientes', async () => {
    const { queue, store } = setup();
    await queue.enqueue(req('a'));
    await queue.enqueue(req('b'));
    await queue.applyResults([store.rows.get('b')!], [{ client_uuid: 'b', result: 'created' }]);
    await queue.purge(true);
    expect([...store.rows.keys()]).toEqual(['a']);
  });

  it('sync: red caída aplica backoff; kiosco revocado no penaliza', async () => {
    const { queue, store } = setup();
    await queue.enqueue(req('a'));
    const net = await syncKiosk(queue, () => Promise.reject(new NetworkError('x')), true);
    expect(net.stoppedBy).toBe('network');
    expect(store.rows.get('a')!.attempts).toBe(1);
    const auth = await syncKiosk(queue, () => Promise.reject(new ApiRequestError(401, { code: 'unauthenticated', message: 'x' })), true);
    expect(auth.stoppedBy).toBe('auth');
    expect(store.rows.get('a')!.attempts).toBe(1);
    const ok = await syncKiosk(queue, async (p) => ({ results: p.map((x) => ({ client_uuid: x.client_uuid, result: 'created' as const })) }), true);
    expect(ok.confirmed).toBe(1);
    expect(store.rows.get('a')!.status).toBe('synced');
  });
});

describe('envío de marcaje del kiosco', () => {
  const receipt = { data: { receipt_code: 'F-1', punch: { uuid: 'p', type: 'clock_in' as const, server_time: '2026-11-23T15:00:05Z', status: 'accepted' }, employee: { first_name: 'Ana' } } };

  it('confirmado sólo con respuesta del servidor', async () => {
    const { queue } = setup();
    const out = await submitKioskPunch(req('a'), { isOnline: true, allowOffline: true, send: async () => receipt, queue });
    expect(out).toEqual({ kind: 'confirmed', time: '2026-11-23T15:00:05Z', firstName: 'Ana', receipt: 'F-1' });
    expect(await queue.list()).toHaveLength(0);
  });

  it('sin red queda en cola (nunca confirmado)', async () => {
    const { queue } = setup();
    const out = await submitKioskPunch(req('a'), { isOnline: true, allowOffline: true, send: () => Promise.reject(new NetworkError('x')), queue });
    expect(out.kind).toBe('queued');
    expect(await queue.list()).toHaveLength(1);
  });

  it('sin red y sin permiso offline: error', async () => {
    const { queue } = setup();
    await expect(submitKioskPunch(req('a'), { isOnline: false, allowOffline: false, send: async () => receipt, queue })).rejects.toBeInstanceOf(KioskPunchError);
  });

  it('credenciales inválidas: mensaje genérico sin revelar cuál falló', () => {
    const e = toKioskError(new ApiRequestError(422, { code: 'validation_failed', message: 'El NIP no coincide' }));
    expect(e.message).toBe('Número o NIP incorrecto');
    expect(e.retry).toBe('pin');
    expect(toKioskError(new ApiRequestError(429, { code: 'rate_limited', message: 'x' })).retry).toBe('idle');
    expect(toKioskError(new ApiRequestError(401, { code: 'unauthenticated', message: 'x' })).kioskRevoked).toBe(true);
  });

  it('códigos del contrato B1', () => {
    expect(toKioskError(new ApiRequestError(422, { code: 'kiosk_invalid_credentials', message: 'x' })).message).toBe('Número o NIP incorrecto');
    expect(toKioskError(new ApiRequestError(423, { code: 'pin_locked', message: 'x' })).retry).toBe('idle');
    expect(toKioskError(new ApiRequestError(401, { code: 'kiosk_unauthenticated', message: 'x' })).kioskRevoked).toBe(true);
    expect(toKioskError(new ApiRequestError(422, { code: 'kiosk_wrong_branch', message: 'No es tu sucursal' })).message).toBe('No es tu sucursal');
    expect(
      toKioskError(new ApiRequestError(422, { code: 'policy_violation', message: 'Se requiere una verificación facial vigente para marcar.' })).retry,
    ).toBe('face');
  });
});

describe('flujo de la pantalla del kiosco', () => {
  const run = (actions: KioskAction[], from: KioskFlow = initialKioskFlow) => actions.reduce(kioskReducer, from);
  const digits = (s: string): KioskAction[] => s.split('').map((d) => ({ type: 'digit', d }));

  it('inicio → número → NIP → tipo → enviando → confirmación', () => {
    let s = run([{ type: 'start' }, ...digits('1024'), { type: 'next', requiresFace: false }]);
    expect(s).toEqual({ step: 'pin', employee: '1024', digits: '' });
    s = run([...digits('12'), { type: 'next', requiresFace: false }], s);
    expect(s.step).toBe('pin'); // NIP demasiado corto
    s = run([...digits('34'), { type: 'next', requiresFace: false }], s);
    expect(s).toEqual({ step: 'type', employee: '1024', pin: '1234', faceId: null });
    s = run([{ type: 'choose', punchType: 'clock_out' }], s);
    expect(s.step).toBe('sending');
    s = run([{ type: 'success', outcome: { kind: 'queued', time: '2026-11-23T15:00:00Z' } }], s);
    expect(s).toEqual({ step: 'done', type: 'clock_out', outcome: { kind: 'queued', time: '2026-11-23T15:00:00Z' } });
    expect(autoResetMs(s)).toBe(DONE_TIMEOUT_MS);
    expect(run([{ type: 'reset' }], s)).toEqual(initialKioskFlow);
  });

  it('exige rostro cuando el kiosco lo pide', () => {
    let s = run([{ type: 'start' }, ...digits('7'), { type: 'next', requiresFace: true }, ...digits('9999'), { type: 'next', requiresFace: true }]);
    expect(s).toEqual({ step: 'face', employee: '7', pin: '9999' });
    s = run([{ type: 'faceOk', id: 'fv-1' }], s);
    expect(s).toEqual({ step: 'type', employee: '7', pin: '9999', faceId: 'fv-1' });
  });

  it('si el servidor exige rostro al enviar, pasa al paso facial con el mismo número y NIP', () => {
    let s = run([{ type: 'start' }, ...digits('8'), { type: 'next', requiresFace: false }, ...digits('2468'), { type: 'next', requiresFace: false }, { type: 'choose', punchType: 'clock_in' }]);
    s = run([{ type: 'failure', message: 'Falta verificar tu rostro.', retry: 'face' }], s);
    expect(s).toEqual({ step: 'face', employee: '8', pin: '2468' });
    s = run([{ type: 'faceOk', id: 'fv-9' }, { type: 'choose', punchType: 'clock_in' }], s);
    expect(s).toEqual({ step: 'sending', employee: '8', pin: '2468', faceId: 'fv-9', type: 'clock_in' });
    // Con rostro ya verificado no se repite el ciclo: queda como error.
    expect(run([{ type: 'failure', message: 'x', retry: 'face' }], s).step).toBe('error');
  });

  it('el error de NIP no conserva el NIP; la inactividad regresa a inicio', () => {
    let s = run([{ type: 'start' }, ...digits('5'), { type: 'next', requiresFace: false }, ...digits('1111'), { type: 'next', requiresFace: false }, { type: 'choose', punchType: 'clock_in' }]);
    s = run([{ type: 'failure', message: 'Número o NIP incorrecto', retry: 'pin' }], s);
    expect(s.step).toBe('error');
    expect(JSON.stringify(s)).not.toContain('1111');
    s = run([{ type: 'retry' }], s);
    expect(s).toEqual({ step: 'pin', employee: '5', digits: '' });
    expect(autoResetMs(s)).toBe(IDLE_TIMEOUT_MS);
    expect(autoResetMs(initialKioskFlow)).toBeNull();
  });

  it('ignora caracteres no numéricos y respeta longitudes', () => {
    const s = run([{ type: 'start' }, { type: 'digit', d: 'a' }, ...digits('1234567890123')]);
    expect(s).toEqual({ step: 'number', digits: '123456789012' });
  });
});

describe('cliente del kiosco', () => {
  const STATUS = {
    id: 'k-uuid',
    name: 'Kiosco Centro',
    status: 'active',
    branch: { id: 3, name: 'Sucursal Centro' },
    organization: { id: 1, name: 'Mr. Lana', timezone: 'America/Mexico_City' },
    activated_at: null,
    last_seen_at: null,
    server_time: '2026-11-24T15:00:00Z',
  };

  it('usa el token del kiosco y no envía token en la activación (contrato B1)', async () => {
    const f = jest
      .fn()
      .mockImplementationOnce(async () => ({ status: 200, ok: true, text: async () => JSON.stringify({ data: STATUS }) }))
      .mockImplementationOnce(async () => ({ status: 201, ok: true, text: async () => JSON.stringify({ data: { token: 't', kiosk: STATUS } }) }));
    const api = createKioskClient({ baseUrl: 'http://x/api/v1', getToken: () => 'kiosk-tok', fetchImpl: f as unknown as typeof fetch });
    const st = await api.status();
    expect(f.mock.calls[0][0]).toBe('http://x/api/v1/kiosk/status');
    expect(f.mock.calls[0][1].headers.Authorization).toBe('Bearer kiosk-tok');
    expect(st).toMatchObject({ active: true, server_time: '2026-11-24T15:00:00Z', kiosk: { name: 'Kiosco Centro', branch: 'Sucursal Centro', timezone: 'America/Mexico_City' } });
    const act = await api.activate({ activation_code: 'ABC', device: {} as never });
    expect(f.mock.calls[1][1].headers.Authorization).toBeUndefined();
    expect(act.token).toBe('t');
  });

  it('un kiosco revocado o pendiente se reporta como inactivo', async () => {
    const f = jest.fn().mockImplementation(async () => ({ status: 200, ok: true, text: async () => JSON.stringify({ data: { ...STATUS, status: 'revoked' } }) }));
    const api = createKioskClient({ baseUrl: 'http://x/api/v1', getToken: () => 'k', fetchImpl: f as unknown as typeof fetch });
    expect((await api.status()).active).toBe(false);
  });

  it('el recibo sólo muestra el primer nombre de quien marcó', async () => {
    const receipt = {
      id: '3f2c1a9e-0b7d-4c5e-9a1f-2b3c4d5e6f70',
      client_uuid: 'c',
      type: 'clock_in',
      server_time: '2026-11-24T15:00:05Z',
      status: 'accepted',
      captured_offline: false,
      employee_name: 'Ana María López Pérez',
      duplicate: false,
    };
    const f = jest.fn().mockImplementation(async () => ({ status: 201, ok: true, text: async () => JSON.stringify({ data: receipt }) }));
    const api = createKioskClient({ baseUrl: 'http://x/api/v1', getToken: () => 'k', fetchImpl: f as unknown as typeof fetch });
    const r = await api.punch({} as never);
    expect(r.data.employee).toEqual({ first_name: 'Ana' });
    expect(r.data.punch.server_time).toBe('2026-11-24T15:00:05Z');
    expect(JSON.stringify(r.data)).not.toContain('López');
  });

  it('el reto facial usa los endpoints /kiosk/face/* con número y NIP', async () => {
    const f = jest.fn().mockImplementation(async () => ({ status: 201, ok: true, text: async () => JSON.stringify({ data: { id: 'ch', steps: [], expires_at: 'x' } }) }));
    const api = createKioskClient({ baseUrl: 'http://x/api/v1', getToken: () => 'k', fetchImpl: f as unknown as typeof fetch });
    await api.faceChallenge('1024', '583920');
    expect(f.mock.calls[0][0]).toBe('http://x/api/v1/kiosk/face/challenge');
    expect(JSON.parse(f.mock.calls[0][1].body)).toEqual({ employee_number: '1024', pin: '583920' });
  });

  it('la huella del código de salida no depende de mayúsculas ni espacios', () => {
    expect(activationHash(' abc-123 ')).toBe(activationHash('ABC-123'));
    expect(activationHash('ABC-123')).not.toBe(activationHash('ABC-124'));
  });
});
