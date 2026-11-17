/**
 * Contrato API v1 — MR LANA Asistencias.
 *
 * Fuente única de verdad para los tipos intercambiados entre el backend Laravel
 * (`Web/`, rutas `/api/v1/*`) y la aplicación móvil (`Mobile/`).
 * Cualquier cambio aquí debe reflejarse en los API Resources de Laravel y en
 * las pruebas de contrato (`Web/tests/Feature/Api/ContractTest.php`).
 *
 * Convenciones:
 *  - Fechas-hora: ISO-8601 en UTC con sufijo `Z` (p. ej. `2026-11-17T15:04:05Z`).
 *  - Fechas: `YYYY-MM-DD` (fecha civil en la zona horaria del centro de trabajo).
 *  - Duraciones: minutos enteros (`*_minutes`). Nunca flotantes.
 *  - Importes: string decimal (`"1234.50"`). Nunca flotantes.
 *  - Respuesta exitosa: `{ data: T, meta?: M }`.
 *  - Error: `ApiError` con código HTTP correcto (401, 403, 404, 409, 410, 422, 423, 429, 5xx).
 */

export type IsoDateTime = string;
export type IsoDate = string;
export type Uuid = string;

export interface ApiEnvelope<T, M = undefined> {
    data: T;
    meta?: M;
}

export interface PaginationMeta {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export type ApiErrorCode =
    | 'unauthenticated'
    | 'forbidden'
    | 'not_found'
    | 'validation_failed'
    | 'device_pending'
    | 'device_revoked'
    | 'device_unknown'
    | 'device_in_use'
    | 'invalid_signature'
    | 'challenge_expired'
    | 'challenge_used'
    | 'duplicate_punch'
    | 'invalid_sequence'
    | 'location_required'
    | 'outside_geofence'
    | 'policy_violation'
    | 'no_employment'
    | 'rate_limited'
    | 'immutable_record'
    | 'server_error'
    | 'face_unavailable'
    | 'face_not_enrolled'
    | 'face_locked'
    | 'face_challenge_invalid'
    | 'face_challenge_expired';

export interface ApiError {
    message: string;
    code: ApiErrorCode;
    errors?: Record<string, string[]>;
}

/* ───────────────────────────── Autenticación ───────────────────────────── */

export type Platform = 'android' | 'ios' | 'web';

export interface DeviceInfo {
    /** UUID generado por la app en la primera ejecución y persistido en SecureStore. */
    device_uuid: Uuid;
    /**
     * Identificador del teléfono físico (Android: `Application.getAndroidId()`).
     * Un mismo teléfono no puede vincularse a dos personas (409 `device_in_use`).
     */
    hardware_id: string | null;
    name: string;
    platform: Platform;
    os_version: string | null;
    app_version: string;
    model: string | null;
    /** true si el SO reporta hardware biométrico enrolado. */
    biometric_available: boolean;
}

/** POST /api/v1/auth/login */
export interface LoginRequest {
    email: string;
    password: string;
    device: DeviceInfo;
}

export type DeviceStatus = 'pending' | 'approved' | 'revoked';

export interface Device {
    id: number;
    device_uuid: Uuid;
    name: string;
    platform: Platform;
    model: string | null;
    status: DeviceStatus;
    /** Motivo de la revisión cuando `status = 'pending'`. */
    pending_reason: 'device_change' | 'new_device' | 'relink' | null;
    approved_at: IsoDateTime | null;
    revoked_at: IsoDateTime | null;
    last_seen_at: IsoDateTime | null;
    is_current: boolean;
}

export interface LoginResponse {
    token: string;
    token_expires_at: IsoDateTime;
    /**
     * Secreto del dispositivo (base64, 32 bytes). Se entrega UNA sola vez cuando
     * el dispositivo se registra por primera vez o tras re-vinculación.
     * La app debe guardarlo en SecureStore con `requireAuthentication: true`
     * para que sólo pueda leerse tras autenticación biométrica/credencial del SO.
     * null si el dispositivo ya estaba vinculado.
     */
    device_secret: string | null;
    device: Device;
    profile: Profile;
}

/** POST /api/v1/auth/refresh → rota el token actual. */
export interface RefreshResponse {
    token: string;
    token_expires_at: IsoDateTime;
}

/* ─────────────────────────────── Perfil ─────────────────────────────── */

export interface OrganizationSummary {
    id: number;
    name: string;
    timezone: string;
}

export interface Profile {
    user: {
        id: number;
        name: string;
        email: string;
    };
    organization: OrganizationSummary;
    employee: {
        id: number;
        employee_number: string;
        full_name: string;
        first_name: string;
        position: string | null;
        department: string | null;
        branch: string | null;
        work_center: string | null;
        hire_date: IsoDate | null;
        supervisor: string | null;
        timezone: string;
    } | null;
    /** Documentos del sistema de registro pendientes de acuse por el colaborador. */
    pending_acknowledgements: AcknowledgementDocument[];
}

export interface AcknowledgementDocument {
    id: number;
    code: string;
    title: string;
    version: string;
    body_markdown: string;
    published_at: IsoDateTime;
}

/** POST /api/v1/acknowledgements/{id} */
export interface AcknowledgeRequest {
    accepted: true;
    device_uuid: Uuid;
}

/* ─────────────────────────── Políticas de marcaje ─────────────────────────── */

export type LocationMode =
    | 'free'            // desde cualquier lugar, sin ubicación
    | 'record'          // desde cualquier lugar, se registra ubicación si está disponible
    | 'required'        // ubicación obligatoria, cualquier lugar
    | 'geofence'        // ubicación obligatoria dentro de geocerca autorizada
    | 'device_only';    // sólo desde dispositivo corporativo autorizado

export type AuthMethod =
    | 'device_biometric'   // biometría del SO desbloquea el secreto del dispositivo
    | 'device_credential'  // PIN/patrón del SO desbloquea el secreto del dispositivo
    | 'face_verification'  // verificación facial 1:1 con prueba de vida (motor open source en servidor)
    | 'web_session'        // marcaje web (si la política lo permite)
    | 'system';            // salida automática por política (nunca la envía la app)

export interface Geofence {
    id: number;
    name: string;
    latitude: number;
    longitude: number;
    radius_meters: number;
}

export interface AttendancePolicy {
    location_mode: LocationMode;
    allowed_auth_methods: AuthMethod[];
    require_strong_biometric: boolean;
    allow_offline: boolean;
    /** Máximo de horas que un marcaje offline puede tardar en sincronizarse antes de ir a revisión. */
    offline_max_hours: number;
    geofences: Geofence[];
    face_verification_enabled: boolean;
}

/* ──────────────────────────────── Marcajes ──────────────────────────────── */

export type PunchType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';

export type PunchStatus =
    | 'accepted'        // aceptado y sellado por el servidor
    | 'flagged'         // aceptado con anomalías: requiere revisión (nunca se descarta)
    | 'pending_sync';   // sólo existe en el cliente (nunca lo emite el servidor)

export type PunchSource = 'mobile' | 'mobile_offline' | 'web' | 'kiosk' | 'import' | 'system';

export interface LocationEvidence {
    latitude: number;
    longitude: number;
    accuracy_meters: number | null;
    captured_at: IsoDateTime;
    /** Valor de `mocked` reportado por el SO (Android). No es prueba infalible. */
    is_mocked: boolean | null;
}

/** POST /api/v1/attendance/challenge → nonce de un solo uso (TTL 120 s). */
export interface ChallengeResponse {
    nonce: string;
    expires_at: IsoDateTime;
}

/**
 * Cuerpo de marcaje. Firma:
 *   signature = hex(HMAC_SHA256(base64decode(device_secret), canonical))
 *   canonical = [client_uuid, type, device_time, nonce ?? '', device_uuid].join('|')
 */
export interface PunchRequest {
    client_uuid: Uuid;
    type: PunchType;
    device_time: IsoDateTime;
    timezone: string;
    device_uuid: Uuid;
    auth_method: AuthMethod;
    /** Requerido en línea; null para marcajes capturados sin conexión. */
    nonce: string | null;
    signature: string;
    captured_offline: boolean;
    location: LocationEvidence | null;
    /** Id de verificación facial previa (FaceVerificationResult.id) si aplica. */
    face_verification_id: Uuid | null;
}

export interface Punch {
    uuid: Uuid;
    type: PunchType;
    status: Exclude<PunchStatus, 'pending_sync'>;
    source: PunchSource;
    /** Hora oficial: recepción en servidor. */
    server_time: IsoDateTime;
    /** Hora declarada por el dispositivo (no certificada). */
    device_time: IsoDateTime | null;
    clock_skew_seconds: number | null;
    timezone: string;
    auth_method: AuthMethod;
    captured_offline: boolean;
    anomalies: PunchAnomaly[];
    location: LocationEvidence | null;
    /** Hash de integridad encadenado (sha256 hex). */
    hash: string;
    sequence: number;
    /** Si existe rectificación aprobada que afecta a este evento. */
    rectified: boolean;
}

export type PunchAnomaly =
    | 'clock_skew'
    | 'late_sync'
    | 'mocked_location'
    | 'outside_geofence'
    | 'low_accuracy'
    | 'no_location'
    | 'out_of_sequence'
    | 'outside_schedule'
    | 'rest_day'
    | 'holiday'
    | 'offline_not_allowed';

export interface PunchReceipt {
    punch: Punch;
    /** Folio verificable mostrado como comprobante digital. */
    receipt_code: string;
    workday: Workday;
}

/** POST /api/v1/sync/punches */
export interface SyncRequest {
    punches: PunchRequest[];
}

export type SyncItemResult =
    | { client_uuid: Uuid; result: 'created'; punch: Punch }
    | { client_uuid: Uuid; result: 'duplicate'; punch: Punch }
    | { client_uuid: Uuid; result: 'rejected'; error: ApiError };

export interface SyncResponse {
    results: SyncItemResult[];
}

/* ─────────────────────────────── Jornada ─────────────────────────────── */

export type ShiftKind = 'day' | 'night' | 'mixed';

export interface ScheduledShift {
    date: IsoDate;
    starts_at: IsoDateTime;
    ends_at: IsoDateTime;
    kind: ShiftKind;
    break_minutes: number;
    tolerance_minutes: number;
    schedule_name: string;
}

export type WorkdayStatus =
    | 'not_started'
    | 'working'
    | 'on_break'
    | 'finished'
    | 'rest_day'
    | 'holiday'
    | 'on_leave'
    /** Día pasado con turno programado y sin marcajes. */
    | 'absent'
    /** Día pasado con entrada sin salida registrada. */
    | 'incomplete';

export interface Workday {
    date: IsoDate;
    status: WorkdayStatus;
    shift: ScheduledShift | null;
    punches: Punch[];
    next_expected: PunchType | null;
    /** Minutos de presencia registrada (entrada→salida menos descansos registrados). */
    worked_minutes: number;
    break_minutes: number;
    late_minutes: number;
    /** Minutos que exceden la jornada programada (no implica horas extra autorizadas). */
    excess_minutes: number;
    /** Hora máxima de salida aplicada (cierre automático), si está configurada. */
    auto_clock_out_at: IsoDateTime | null;
    /** Tiempo registrado después del cierre automático: se conserva, no cuenta como extra. */
    after_cutoff_minutes: number;
    incidences: IncidenceSummary[];
}

/** GET /api/v1/attendance/today */
export interface TodayResponse {
    workday: Workday;
    policy: AttendancePolicy;
    week: WeekSummary;
    server_time: IsoDateTime;
}

export interface WeekSummary {
    week_start: IsoDate;
    worked_minutes: number;
    scheduled_minutes: number;
    legal_max_minutes: number;
    overtime_minutes: number;
    days: { date: IsoDate; status: WorkdayStatus; worked_minutes: number }[];
}

/** GET /api/v1/attendance/history?from=YYYY-MM-DD&to=YYYY-MM-DD&page=1 */
export interface HistoryDay {
    date: IsoDate;
    status: WorkdayStatus;
    worked_minutes: number;
    late_minutes: number;
    punches: Punch[];
    rectifications: Rectification[];
}

/* ───────────────────────── Rectificaciones ───────────────────────── */

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export interface Rectification {
    id: number;
    date: IsoDate;
    punch_uuid: Uuid | null;
    kind: 'missing_punch' | 'wrong_type' | 'time_clarification' | 'other';
    proposed_type: PunchType | null;
    proposed_time: IsoDateTime | null;
    reason: string;
    status: ApprovalStatus;
    reviewer: string | null;
    review_comment: string | null;
    reviewed_at: IsoDateTime | null;
    created_at: IsoDateTime;
}

/** POST /api/v1/rectifications (multipart si incluye `evidence`) */
export interface RectificationRequest {
    date: IsoDate;
    punch_uuid: Uuid | null;
    kind: Rectification['kind'];
    proposed_type: PunchType | null;
    proposed_time: IsoDateTime | null;
    reason: string;
}

/* ──────────────────────────── Incidencias ──────────────────────────── */

export interface IncidenceType {
    id: number;
    code: string;
    name: string;
    category: 'absence' | 'leave' | 'disability' | 'vacation' | 'time' | 'other';
    requires_evidence: boolean;
    requires_approval: boolean;
    employee_requestable: boolean;
}

export interface IncidenceSummary {
    id: number;
    type_code: string;
    type_name: string;
    status: ApprovalStatus;
}

export interface Incidence {
    id: number;
    type: IncidenceType;
    starts_on: IsoDate;
    ends_on: IsoDate;
    reason: string | null;
    status: ApprovalStatus;
    has_evidence: boolean;
    reviewer: string | null;
    review_comment: string | null;
    created_at: IsoDateTime;
}

/** POST /api/v1/incidences (multipart si incluye `evidence`) */
export interface IncidenceRequest {
    incidence_type_id: number;
    starts_on: IsoDate;
    ends_on: IsoDate;
    reason: string;
}

/* ──────────────────────────── Vacaciones ──────────────────────────── */

export interface VacationBalance {
    /** Año de servicio en curso (1 = primer año). */
    service_year: number;
    period_starts_on: IsoDate;
    period_ends_on: IsoDate;
    legal_days: number;
    extra_days: number;
    entitled_days: number;
    used_days: number;
    pending_days: number;
    available_days: number;
    /** Porcentaje de prima vacacional aplicable (string decimal, mínimo legal "25.00"). */
    vacation_premium_percent: string;
    /** Trazabilidad: regla y versión usadas para el cálculo. */
    rule_source: string;
}

export interface VacationRequest {
    id: number;
    starts_on: IsoDate;
    ends_on: IsoDate;
    days: number;
    status: ApprovalStatus;
    comment: string | null;
    reviewer: string | null;
    review_comment: string | null;
    created_at: IsoDateTime;
}

/** POST /api/v1/vacations */
export interface VacationRequestBody {
    starts_on: IsoDate;
    ends_on: IsoDate;
    comment: string | null;
}

/* ───────────────────────────── Calendario ───────────────────────────── */

export type CalendarEntryKind =
    | 'shift'
    | 'rest_day'
    | 'holiday'
    | 'vacation'
    | 'leave'
    | 'disability'
    | 'incidence';

export interface CalendarEntry {
    date: IsoDate;
    kind: CalendarEntryKind;
    label: string;
    starts_at: IsoDateTime | null;
    ends_at: IsoDateTime | null;
}

/** GET /api/v1/calendar?month=YYYY-MM */
export interface CalendarResponse {
    month: string;
    entries: CalendarEntry[];
}

/* ─────────────────────────── Notificaciones ─────────────────────────── */

/** Categoría del aviso (ícono/agrupación en la bandeja). */
export type NotificationCategory = 'message' | 'reminder' | 'request' | 'attendance' | 'system';

/**
 * Aviso de la bandeja (GET /notifications). Los comunicados retirados no aparecen.
 * `data` trae sólo identificadores en texto para abrir el detalle, p. ej.
 * `{announcement_id}`, `{subject, subject_id, status}`, `{date, action}`,
 * `{punch_uuid, date}`, `{device_uuid, status}`.
 */
export interface AppNotification {
    id: Uuid;
    /** p. ej. `announcement`, `reminder.late_clock_in`, `reminder.clock_out`, `attendance.auto_clock_out`, `request.approved`, `device.revoked`. */
    kind: string;
    category: NotificationCategory;
    priority: 'normal' | 'high';
    title: string;
    body: string;
    data: Record<string, string>;
    read_at: IsoDateTime | null;
    created_at: IsoDateTime;
}

/**
 * POST /api/v1/devices/push-token — token de Firebase Cloud Messaging
 * (`messaging().getToken()`). Repetir al rotar el token.
 */
export interface PushTokenRequest {
    device_uuid: Uuid;
    push_token: string;
    provider: 'fcm';
}

/**
 * `data` de un push FCM (todas las claves son texto). El texto visible es
 * neutro; el detalle se lee en la bandeja con `notification_id`.
 */
export type PushData = Record<string, string> & {
    notification_id: Uuid;
    category: NotificationCategory;
    kind: string;
    priority: 'normal' | 'high';
};

/* ────────────────────────── Verificación facial ────────────────────────── */

/**
 * Verificación facial 1:1 con modelos open source (OpenCV YuNet + SFace,
 * MiniFASNet) en un microservicio interno. Prueba de vida = pasiva (anti-
 * spoofing en TODOS los cuadros) + reto activo aleatorio de 2 pasos.
 * NO es una solución certificada ISO/IEC 30107-3. Ver docs/seguridad/BIOMETRIA.md.
 *
 * Flujo de la app:
 * 1. GET status → si `available && enrolled`, ofrecer la modalidad.
 * 2. POST challenge → mostrar `steps[].label` en orden (≤ 30 s).
 * 3. Capturar 6–10 cuadros JPEG durante el reto: el PRIMERO de frente y quieto,
 *    luego cada paso en orden. POST verify (multipart).
 * 4. Si `result === 'match'`, enviar `id` como `PunchRequest.face_verification_id`
 *    antes de `expires_at` (2 min, un solo uso).
 * Si la persona no consiente: usar `device_biometric` (huella/rostro del SO).
 */
export interface FaceVerificationStatus {
    available: boolean;
    /** 'opencv-open-source' si está disponible; null si no. */
    provider: 'opencv-open-source' | null;
    enrolled: boolean;
    /** Siempre true: el registro exige consentimiento expreso por escrito ante RH. */
    consent_required: boolean;
}

export type FaceChallengeStepCode = 'turn_left' | 'turn_right' | 'move_closer' | 'move_away';

/** POST /api/v1/face-verification/challenge → 201 */
export interface FaceChallenge {
    id: Uuid;
    /** 2 pasos distintos (al menos un giro), en el orden en que deben hacerse. `label` en español para mostrar. */
    steps: { code: FaceChallengeStepCode; label: string }[];
    /** Emisión + 30 s. */
    expires_at: IsoDateTime;
}

/**
 * POST /api/v1/face-verification/verify — multipart/form-data:
 *   challenge_id   Uuid
 *   frames[]       6–10 archivos image/jpeg (≤ 1.5 MB c/u; recomendado 720p, calidad 0.8)
 *   timestamps[]   enteros: ms transcurridos desde que la app RECIBIÓ el reto
 *                  (Date.now() - t0), uno por cuadro, estrictamente crecientes, ≤ 30000
 *   mirrored       '1' si los cuadros están espejados como en la vista selfie; '0' si no
 *                  (define el sentido de "izquierda"/"derecha" = la del colaborador)
 * Los cuadros se procesan en memoria y se descartan; nunca se almacenan.
 */
export interface FaceVerifyRequestFields {
    challenge_id: Uuid;
    'frames[]': Blob[];
    'timestamps[]': number[];
    mirrored: '0' | '1';
}

export type FaceVerificationResultCode =
    | 'match'
    | 'no_match'
    | 'liveness_failed'
    | 'inconclusive'
    | 'no_face'
    | 'multiple_faces'
    | 'poor_quality'
    | 'error';

export interface FaceVerificationResult {
    /** Usar como `face_verification_id` al marcar (sólo si result === 'match'). */
    id: Uuid;
    result: FaceVerificationResultCode;
    /** 2 min tras la verificación. */
    expires_at: IsoDateTime;
}

/** Códigos de error propios de /face-verification/* (además de ApiErrorCode). */
export type FaceVerificationErrorCode =
    | 'face_unavailable'        // 503: motor no configurado o caído
    | 'face_not_enrolled'       // 409: sin registro activo (acudir con RH)
    | 'face_locked'             // 429: 5 fallos en 15 min; usar otro método
    | 'face_challenge_invalid'  // 422: reto inexistente, ajeno o ya usado
    | 'face_challenge_expired'; // 422: pedir uno nuevo

export type FaceApiError = Omit<ApiError, 'code'> & { code: ApiErrorCode | FaceVerificationErrorCode };

/* ─────────────────── Ubicación de trabajo en tiempo real ─────────────────── */

/**
 * Mismo modelo que la app de Auditoría (D-138/D-139): una sola tarea de GPS
 * (expo-location + expo-task-manager, servicio en primer plano), filtro de
 * cambios significativos, cola local persistente, lotes idempotentes por uuid.
 * Sólo se registra dentro de la ventana de jornada que define el servidor.
 */
export interface LocationPoint {
    uuid: Uuid;
    latitude: number;
    longitude: number;
    accuracy_m: number | null;
    heading: number | null;
    speed_mps: number | null;
    altitude_m: number | null;
    captured_at: IsoDateTime;
    app_state: 'foreground' | 'background';
    is_mocked: boolean | null;
}

/** Ventana en la que la app comparte ubicación (turno del día ± margen). */
export interface LocationWindow {
    enabled: boolean;
    active_now: boolean;
    starts_at: IsoDateTime | null;
    ends_at: IsoDateTime | null;
    next_start_at: IsoDateTime | null;
    timezone: string;
}

export interface LocationSettings {
    window: LocationWindow;
    /** Intervalos sugeridos en segundos. */
    interval_s: { foreground: number; background: number };
    /** Distancia mínima en metros para registrar un cambio. */
    distance_m: { foreground: number; background: number };
}

/** POST /api/v1/location/points — 1 a 50 puntos. */
export interface LocationBatchRequest {
    points: LocationPoint[];
}

export type LocationRejectCode =
    | 'invalid_point'
    | 'invalid_coordinates'
    | 'invalid_accuracy'
    | 'future_time'
    | 'too_old'
    | 'outside_window';

export interface LocationBatchResponse {
    accepted: number;
    duplicates: number;
    rejected: number;
    rejections: { index: number; uuid: Uuid | null; code: LocationRejectCode }[];
    settings: LocationSettings;
}

/** GET /api/v1/location/state */
export interface LocationState {
    last: (LocationPoint & { received_at: IsoDateTime }) | null;
    settings: LocationSettings;
}

/* ──────────────────────────── Endpoints ──────────────────────────── */

/**
 * Mapa de endpoints v1 (todos bajo `/api/v1`, Bearer Sanctum salvo login).
 *
 * POST   /auth/login                    LoginRequest → LoginResponse
 * POST   /auth/refresh                  → RefreshResponse
 * POST   /auth/logout                   → 204
 * GET    /profile                       → Profile
 * POST   /acknowledgements/{id}         AcknowledgeRequest → 204
 * GET    /attendance/today              → TodayResponse
 * GET    /attendance/policy             → AttendancePolicy
 * POST   /attendance/challenge          → ChallengeResponse
 * POST   /attendance/punches            PunchRequest → PunchReceipt (201; 200 si duplicado idempotente)
 * GET    /attendance/punches/{uuid}     → PunchReceipt
 * GET    /attendance/history            → HistoryDay[] + PaginationMeta
 * POST   /sync/punches                  SyncRequest → SyncResponse
 * GET    /rectifications                → Rectification[] + PaginationMeta
 * POST   /rectifications                RectificationRequest → Rectification (201)
 * GET    /incidence-types               → IncidenceType[]
 * GET    /incidences                    → Incidence[] + PaginationMeta
 * POST   /incidences                    IncidenceRequest → Incidence (201)
 * GET    /vacations/balance             → VacationBalance
 * GET    /vacations                     → VacationRequest[] + PaginationMeta
 * POST   /vacations                     VacationRequestBody → VacationRequest (201)
 * POST   /vacations/{id}/cancel         → VacationRequest
 * GET    /calendar                      → CalendarResponse
 * GET    /notifications                 → AppNotification[] + PaginationMeta
 * POST   /notifications/{id}/read       → 204
 * POST   /notifications/read-all        → 204
 * GET    /devices                       → Device[]
 * POST   /devices/push-token            PushTokenRequest → 204
 * GET    /face-verification/status      → FaceVerificationStatus
 * POST   /face-verification/challenge   → FaceChallenge (201; límite 20/min, bloqueo 5 fallos/15 min)
 * POST   /face-verification/verify      multipart FaceVerifyRequestFields → FaceVerificationResult (200)
 * POST   /location/points               LocationBatchRequest → LocationBatchResponse (200)
 * GET    /location/state                → LocationState
 * GET    /health                        → { status: 'ok', time: IsoDateTime } (público)
 */
export const API_VERSION = 'v1' as const;
