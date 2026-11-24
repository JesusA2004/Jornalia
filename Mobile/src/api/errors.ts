import type { ApiError, ApiErrorCode } from '@/types/contracts';

const KNOWN_CODES: readonly ApiErrorCode[] = [
  'unauthenticated',
  'forbidden',
  'not_found',
  'validation_failed',
  'device_pending',
  'device_revoked',
  'device_unknown',
  'device_in_use',
  'invalid_signature',
  'challenge_expired',
  'challenge_used',
  'duplicate_punch',
  'invalid_sequence',
  'location_required',
  'outside_geofence',
  'policy_violation',
  'no_employment',
  'rate_limited',
  'immutable_record',
  'server_error',
  'face_unavailable',
  'face_not_enrolled',
  'face_locked',
  'face_challenge_invalid',
  'face_challenge_expired',
  'kiosk_unauthenticated',
  'kiosk_activation_invalid',
  'kiosk_invalid_credentials',
  'kiosk_wrong_branch',
  'kiosk_employee_inactive',
  'pin_locked',
  'pin_not_set',
  'face_verification_required',
  'documents_pending',
];

/** Error devuelto por el servidor con forma `ApiError`. */
export class ApiRequestError extends Error {
  readonly status: number;
  readonly code: ApiErrorCode;
  readonly fieldErrors: Record<string, string[]>;
  /** Código anterior equivalente (compatibilidad con apps instaladas), p. ej. `policy_violation`. */
  readonly legacyCode: ApiErrorCode | undefined;

  constructor(status: number, body: ApiError) {
    super(body.message);
    this.name = 'ApiRequestError';
    this.status = status;
    this.code = body.code;
    this.fieldErrors = body.errors ?? {};
    this.legacyCode = body.legacy_code;
  }

  toApiError(): ApiError {
    const base: ApiError = { message: this.message, code: this.code, errors: this.fieldErrors };
    return this.legacyCode ? { ...base, legacy_code: this.legacyCode } : base;
  }

  /** 5xx o 429: puede reintentarse más tarde. */
  get isRetriable(): boolean {
    return this.status >= 500 || this.status === 429;
  }
}

/** No hubo respuesta del servidor (sin red, DNS, tiempo agotado). */
export class NetworkError extends Error {
  readonly timedOut: boolean;
  constructor(message: string, timedOut = false) {
    super(message);
    this.name = 'NetworkError';
    this.timedOut = timedOut;
  }
}

export function isKnownCode(code: unknown): code is ApiErrorCode {
  return typeof code === 'string' && (KNOWN_CODES as readonly string[]).includes(code);
}

/** Código por defecto cuando el servidor no envía un `ApiError` válido. */
export function codeForStatus(status: number): ApiErrorCode {
  switch (status) {
    case 401:
      return 'unauthenticated';
    case 403:
      return 'forbidden';
    case 404:
      return 'not_found';
    case 409:
      return 'duplicate_punch';
    case 410:
      return 'challenge_expired';
    case 422:
      return 'validation_failed';
    case 423:
      return 'immutable_record';
    case 429:
      return 'rate_limited';
    default:
      return 'server_error';
  }
}

/** Normaliza cualquier cuerpo de error a `ApiError`. */
export function toApiError(status: number, body: unknown): ApiError {
  if (body && typeof body === 'object') {
    const b = body as Partial<ApiError>;
    const code = isKnownCode(b.code) ? b.code : codeForStatus(status);
    const message = typeof b.message === 'string' && b.message.length > 0 ? b.message : defaultMessage(code);
    const errors = b.errors && typeof b.errors === 'object' ? b.errors : undefined;
    const legacy = isKnownCode(b.legacy_code) ? b.legacy_code : undefined;
    return { message, code, ...(errors ? { errors } : {}), ...(legacy ? { legacy_code: legacy } : {}) };
  }
  const code = codeForStatus(status);
  return { message: defaultMessage(code), code };
}

/** Mensajes de respaldo en español cuando el servidor no envía uno. */
export function defaultMessage(code: ApiErrorCode): string {
  const map: Record<ApiErrorCode, string> = {
    unauthenticated: 'Tu sesión expiró. Inicia sesión de nuevo.',
    forbidden: 'No tienes permiso para realizar esta acción.',
    not_found: 'No se encontró la información solicitada.',
    validation_failed: 'Revisa los datos capturados.',
    device_pending: 'Este dispositivo está pendiente de aprobación.',
    device_revoked: 'Este dispositivo fue revocado por tu empresa.',
    device_unknown: 'Este dispositivo no está registrado.',
    device_in_use: 'Este teléfono pertenece a otro colaborador.',
    invalid_signature: 'La firma del marcaje no es válida.',
    challenge_expired: 'La verificación expiró. Intenta de nuevo.',
    challenge_used: 'La verificación ya fue utilizada. Intenta de nuevo.',
    duplicate_punch: 'Este marcaje ya fue registrado.',
    invalid_sequence: 'El tipo de marcaje no corresponde a la secuencia de tu jornada.',
    location_required: 'Tu empresa requiere ubicación para marcar.',
    outside_geofence: 'Estás fuera de las zonas autorizadas para marcar.',
    policy_violation: 'La política de marcaje de tu empresa no permite esta acción.',
    no_employment: 'Tu usuario no tiene un empleo activo asociado.',
    rate_limited: 'Demasiadas solicitudes. Espera un momento.',
    immutable_record: 'Este registro no puede modificarse.',
    server_error: 'Ocurrió un error en el servidor. Intenta más tarde.',
    face_unavailable: 'La verificación facial no está disponible. Usa tu huella.',
    face_not_enrolled: 'Tu rostro no está registrado: acude con RH.',
    face_locked: 'Demasiados intentos con rostro. Usa tu huella.',
    face_challenge_invalid: 'El reto ya no es válido. Intenta de nuevo.',
    face_challenge_expired: 'Se acabó el tiempo. Intenta de nuevo.',
    kiosk_unauthenticated: 'Este kiosco ya no está activo.',
    kiosk_activation_invalid: 'Código de activación no válido o vencido.',
    kiosk_invalid_credentials: 'Número o NIP incorrecto.',
    kiosk_wrong_branch: 'Este kiosco no corresponde a tu sucursal.',
    kiosk_employee_inactive: 'Tu registro no está activo. Acude con RH.',
    pin_locked: 'NIP bloqueado 15 minutos por intentos fallidos.',
    pin_not_set: 'Primero crea tu NIP de firma.',
    face_verification_required: 'Falta verificar tu rostro.',
    documents_pending: 'Firma tu convenio y acepta el aviso de privacidad para marcar.',
  };
  return map[code];
}

/** Mensaje legible para cualquier error lanzado por la capa de API. */
export function describeError(error: unknown): string {
  if (error instanceof ApiRequestError) return error.message;
  if (error instanceof NetworkError) {
    return error.timedOut
      ? 'El servidor tardó demasiado en responder. Revisa tu conexión.'
      : 'No hay conexión con el servidor. Revisa tu conexión a internet.';
  }
  if (error instanceof Error && error.message) return error.message;
  return 'Ocurrió un error inesperado.';
}
