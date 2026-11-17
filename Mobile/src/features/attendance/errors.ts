export type PunchErrorCode =
  | 'no_secret'
  | 'secret_invalidated'
  | 'auth_cancelled'
  | 'auth_failed'
  | 'auth_unavailable'
  | 'method_not_allowed'
  | 'weak_biometric'
  | 'face_required'
  | 'location_denied'
  | 'location_services_off'
  | 'location_unavailable'
  | 'offline_not_allowed'
  | 'no_policy';

/** Error del flujo de marcaje en el dispositivo (antes de llegar al servidor). */
export class PunchFlowError extends Error {
  readonly code: PunchErrorCode;
  constructor(code: PunchErrorCode, message: string) {
    super(message);
    this.name = 'PunchFlowError';
    this.code = code;
  }
}
