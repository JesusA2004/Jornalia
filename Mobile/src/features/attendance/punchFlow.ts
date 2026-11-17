import { ApiRequestError, NetworkError } from '@/api/errors';
import { signPunch } from '@/crypto/signature';
import type { PunchQueue, QueuedPunch } from '@/features/offline/queue';
import type {
  AttendancePolicy,
  AuthMethod,
  ChallengeResponse,
  LocationEvidence,
  PunchReceipt,
  PunchRequest,
  PunchType,
} from '@/types/contracts';
import { isoNow } from '@/utils/time';

import { PunchFlowError } from './errors';

export type PunchStep = 'checking' | 'locating' | 'verifying' | 'challenge' | 'sending' | 'queueing';

export type PunchOutcome =
  | { kind: 'accepted'; receipt: PunchReceipt; duplicate: boolean }
  | { kind: 'queued'; item: QueuedPunch; reason: 'offline' | 'unreachable' };

export interface PunchContext {
  type: PunchType;
  deviceUuid: string;
  timezone: string;
  policy: AttendancePolicy;
  isOnline: boolean;
  /** Id de una verificación facial `match` vigente (auth_method 'face_verification'). */
  faceVerificationId?: string | null;
}

export interface PunchDeps {
  /** Valida el método permitido; devuelve el AuthMethod a declarar. */
  resolveMethod: () => AuthMethod;
  captureLocation: () => Promise<LocationEvidence | null>;
  /** Pide biometría/credencial al SO y devuelve el secreto en base64. */
  unlockSecret: () => Promise<string>;
  challenge: () => Promise<ChallengeResponse>;
  submit: (body: PunchRequest) => Promise<{ status: number; data: PunchReceipt }>;
  queue: PunchQueue;
  uuid: () => string;
  now?: () => Date;
  onStep?: (step: PunchStep) => void;
}

function isUnreachable(e: unknown): boolean {
  return e instanceof NetworkError || (e instanceof ApiRequestError && e.isRetriable);
}

/**
 * Flujo de marcaje:
 *  1. Validar método de autenticación contra la política.
 *  2. Capturar ubicación (si la política lo pide).
 *  3. Desbloquear el secreto del dispositivo (biometría/credencial del SO).
 *  4. En línea: pedir challenge, firmar con nonce y POST /attendance/punches.
 *     Sin conexión (si la política lo permite): firmar con nonce null y encolar.
 *
 * El resultado sólo es "accepted" cuando el servidor respondió; en otro caso es
 * "queued" (pendiente de sincronización) o se lanza un error.
 */
export async function performPunch(ctx: PunchContext, deps: PunchDeps): Promise<PunchOutcome> {
  const now = deps.now ?? (() => new Date());
  deps.onStep?.('checking');
  if (!ctx.isOnline && !ctx.policy.allow_offline) {
    throw new PunchFlowError('offline_not_allowed', 'Sin conexión. Tu empresa no permite registrar marcajes sin conexión.');
  }
  const authMethod = deps.resolveMethod();

  deps.onStep?.('locating');
  const location = await deps.captureLocation();

  deps.onStep?.('verifying');
  const secret = await deps.unlockSecret();

  const base = {
    client_uuid: deps.uuid(),
    type: ctx.type,
    device_time: isoNow(now()),
    timezone: ctx.timezone,
    device_uuid: ctx.deviceUuid,
    auth_method: authMethod,
    location,
    face_verification_id: ctx.faceVerificationId ?? null,
  } satisfies Omit<PunchRequest, 'nonce' | 'signature' | 'captured_offline'>;

  const enqueueOffline = async (reason: 'offline' | 'unreachable'): Promise<PunchOutcome> => {
    if (!ctx.policy.allow_offline) {
      throw new PunchFlowError(
        'offline_not_allowed',
        'No se pudo contactar al servidor y tu empresa no permite marcajes sin conexión. El marcaje no se registró.',
      );
    }
    deps.onStep?.('queueing');
    const body: PunchRequest = {
      ...base,
      nonce: null,
      captured_offline: true,
      signature: signPunch(secret, { ...base, nonce: null }),
    };
    const item = await deps.queue.enqueue(body);
    return { kind: 'queued', item, reason };
  };

  if (!ctx.isOnline) return enqueueOffline('offline');

  for (let attempt = 0; attempt < 2; attempt++) {
    let challenge: ChallengeResponse;
    deps.onStep?.('challenge');
    try {
      challenge = await deps.challenge();
    } catch (e) {
      if (isUnreachable(e)) return enqueueOffline('unreachable');
      throw e;
    }
    const body: PunchRequest = {
      ...base,
      nonce: challenge.nonce,
      captured_offline: false,
      signature: signPunch(secret, { ...base, nonce: challenge.nonce }),
    };
    deps.onStep?.('sending');
    try {
      const res = await deps.submit(body);
      return { kind: 'accepted', receipt: res.data, duplicate: res.status === 200 };
    } catch (e) {
      if (e instanceof ApiRequestError && (e.code === 'challenge_expired' || e.code === 'challenge_used') && attempt === 0) {
        continue; // un reintento con challenge nuevo (mismo client_uuid)
      }
      if (isUnreachable(e)) return enqueueOffline('unreachable');
      throw e;
    }
  }
  throw new ApiRequestError(410, { code: 'challenge_expired', message: 'La verificación expiró. Intenta de nuevo.' });
}
