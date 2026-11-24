import { ApiRequestError, NetworkError } from '@/api/errors';
import { signPunch } from '@/crypto/signature';
import { PunchFlowError } from '@/features/attendance/errors';
import { performPunch, type PunchDeps } from '@/features/attendance/punchFlow';
import { PunchQueue, type QueuedPunch, type QueueStore } from '@/features/offline/queue';
import type { AttendancePolicy, PunchReceipt, PunchRequest } from '@/types/contracts';

const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

const policy = (o: Partial<AttendancePolicy> = {}): AttendancePolicy => ({
  location_mode: 'record',
  allowed_auth_methods: ['device_biometric'],
  require_strong_biometric: false,
  allow_offline: true,
  offline_max_hours: 72,
  geofences: [],
  face_verification_enabled: false,
  location_tracking: 'on_punch',
  tracking_paused_states: ['break', 'meal'],
  ...o,
});

function memQueue() {
  const rows = new Map<string, QueuedPunch>();
  const store: QueueStore = {
    insert: async (i) => void rows.set(i.client_uuid, i),
    update: async (id, p) => void rows.set(id, { ...rows.get(id)!, ...p }),
    get: async (id) => rows.get(id) ?? null,
    all: async () => [...rows.values()],
    remove: async (id) => void rows.delete(id),
  };
  return new PunchQueue(store, () => 0);
}

function deps(o: Partial<PunchDeps> = {}): PunchDeps & { submitted: PunchRequest[] } {
  const submitted: PunchRequest[] = [];
  return {
    submitted,
    resolveMethod: () => 'device_biometric',
    captureLocation: async () => null,
    unlockSecret: async () => SECRET,
    challenge: async () => ({ nonce: 'n1', expires_at: '2026-11-17T15:02:00Z' }),
    submit: async (b) => {
      submitted.push(b);
      return { status: 201, data: { receipt_code: 'F1' } as PunchReceipt };
    },
    queue: memQueue(),
    uuid: () => 'c-1',
    now: () => new Date('2026-11-17T15:00:00.123Z'),
    ...o,
  };
}

const ctx = (o: Partial<Parameters<typeof performPunch>[0]> = {}) => ({
  type: 'clock_in' as const,
  deviceUuid: 'd-1',
  timezone: 'America/Mexico_City',
  policy: policy(),
  isOnline: true,
  ...o,
});

describe('flujo de marcaje', () => {
  it('en línea: firma con nonce y sólo reporta éxito con respuesta del servidor', async () => {
    const d = deps();
    const out = await performPunch(ctx(), d);
    expect(out.kind).toBe('accepted');
    const body = d.submitted[0]!;
    expect(body.device_time).toBe('2026-11-17T15:00:00Z');
    expect(body.nonce).toBe('n1');
    expect(body.captured_offline).toBe(false);
    expect(body.signature).toBe(signPunch(SECRET, { ...body }));
  });

  it('sin conexión: firma con nonce null y queda pendiente', async () => {
    const d = deps();
    const out = await performPunch(ctx({ isOnline: false }), d);
    expect(out.kind).toBe('queued');
    if (out.kind !== 'queued') return;
    expect(out.item.request.nonce).toBeNull();
    expect(out.item.request.captured_offline).toBe(true);
    expect(out.item.request.signature).toBe(signPunch(SECRET, { ...out.item.request, nonce: null }));
    expect(d.submitted).toHaveLength(0);
  });

  it('sin conexión y política sin offline: error sin pedir biometría', async () => {
    const unlock = jest.fn(async () => SECRET);
    await expect(performPunch(ctx({ isOnline: false, policy: policy({ allow_offline: false }) }), deps({ unlockSecret: unlock }))).rejects.toBeInstanceOf(
      PunchFlowError,
    );
    expect(unlock).not.toHaveBeenCalled();
  });

  it('servidor inalcanzable al enviar: se encola con el mismo client_uuid', async () => {
    const d = deps({ submit: async () => Promise.reject(new NetworkError('x')) });
    const out = await performPunch(ctx(), d);
    expect(out).toMatchObject({ kind: 'queued', reason: 'unreachable' });
    if (out.kind === 'queued') expect(out.item.client_uuid).toBe('c-1');
  });

  it('challenge expirado: reintenta una vez con nonce nuevo', async () => {
    let n = 0;
    let calls = 0;
    const d = deps({
      challenge: async () => ({ nonce: `n${++n}`, expires_at: 'x' }),
      submit: async (b) => {
        calls++;
        if (calls === 1) throw new ApiRequestError(410, { code: 'challenge_expired', message: 'x' });
        return { status: 201, data: { receipt_code: b.nonce } as unknown as PunchReceipt };
      },
    });
    const out = await performPunch(ctx(), d);
    expect(out.kind === 'accepted' && out.receipt.receipt_code).toBe('n2');
  });

  it('rechazo de negocio (422) se propaga y no se encola', async () => {
    const q = memQueue();
    const d = deps({ queue: q, submit: async () => Promise.reject(new ApiRequestError(422, { code: 'invalid_sequence', message: 'Secuencia' })) });
    await expect(performPunch(ctx(), d)).rejects.toMatchObject({ code: 'invalid_sequence' });
    expect(await q.list()).toHaveLength(0);
  });

  it('cancelar la biometría detiene el flujo', async () => {
    const d = deps({ unlockSecret: async () => Promise.reject(new PunchFlowError('auth_cancelled', 'Cancelaste')) });
    await expect(performPunch(ctx(), d)).rejects.toMatchObject({ code: 'auth_cancelled' });
    expect(d.submitted).toHaveLength(0);
  });
});
