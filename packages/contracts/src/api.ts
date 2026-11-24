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
    | 'face_challenge_expired'
    // Kiosco de sucursal (B1)
    | 'kiosk_unauthenticated'
    | 'kiosk_activation_invalid'
    | 'kiosk_invalid_credentials'
    | 'kiosk_wrong_branch'
    | 'kiosk_employee_inactive'
    | 'pin_locked'
    | 'pin_not_set'
    /**
     * Kiosco: la política exige verificación facial y el marcaje no trae una vigente
     * (`POST /kiosk/punches` y elementos de `POST /kiosk/sync`). HTTP 422. Antes se
     * respondía `policy_violation`; el cuerpo trae `legacy_code: 'policy_violation'`
     * y el mismo mensaje por compatibilidad. Obtén una con `POST /kiosk/face/*`.
     */
    | 'face_verification_required'
    /**
     * Marcaje EN LÍNEA (`POST /attendance/punches`, `POST /kiosk/punches` y marcaje web)
     * con el convenio de registro electrónico (`CONVENIO_REGISTRO`) o el aviso de
     * privacidad (`AVISO_PRIVACIDAD`) vigentes sin firmar. HTTP 409. El cuerpo trae
     * `legacy_code: 'policy_violation'` para apps anteriores. La app debe abrir el flujo
     * de documentos (`GET /documents/pending`). Los marcajes sin conexión de
     * `/sync/punches` y `/kiosk/sync` con `device_time` anterior a la recepción NO se
     * rechazan por esta causa: se registran y RH recibe la alerta `punch_without_agreement`.
     * Si la organización no ha publicado el documento, no se exige.
     */
    | 'documents_pending';

export interface ApiError {
    message: string;
    code: ApiErrorCode;
    errors?: Record<string, string[]>;
    /** Código anterior equivalente, sólo cuando `code` sustituyó a uno previo (compatibilidad). */
    legacy_code?: ApiErrorCode;
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
    /**
     * Señales de integridad detectadas por la app (opcional). Generan alerta en
     * Cumplimiento; no bloquean. La detección no es infalible.
     */
    integrity?: DeviceIntegritySignal[];
}

/**
 * Señales de integridad del teléfono. También pueden enviarse en cada marcaje en la
 * cabecera `X-Device-Integrity` (lista separada por comas).
 */
export type DeviceIntegritySignal = 'rooted' | 'jailbroken' | 'emulator' | 'mock_location';

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
    /** true si el colaborador ya configuró su NIP de firma electrónica (A2). Siempre presente en servidores actuales. */
    signature_pin_set?: boolean;
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
    | 'system';            // sólo histórico: marcajes generados por la extinta salida automática (ya no se crean)

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
    /**
     * Parámetro por PUESTO (B2): 'on_punch' = sólo ubicación al marcar;
     * 'during_shift' = seguimiento desde la entrada hasta la salida.
     */
    location_tracking: LocationTracking;
    /** Estados en los que el seguimiento se pausa (descanso / comida registrados). */
    tracking_paused_states: TrackingPausedState[];
    /** Minutos después del fin de turno en que se corta el seguimiento si la salida se omite. */
    location_contingency_minutes?: number;
}

export type LocationTracking = 'on_punch' | 'during_shift';

export type TrackingPausedState = 'break' | 'meal';

/* ──────────────────────────────── Marcajes ──────────────────────────────── */

export type PunchType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';

export type PunchStatus =
    | 'accepted'        // aceptado y sellado por el servidor
    | 'flagged'         // aceptado con anomalías: requiere revisión (nunca se descarta)
    | 'pending_sync';   // sólo existe en el cliente (nunca lo emite el servidor)

/** `system` sólo aparece en marcajes históricos («Generado por sistema (histórico)»). */
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
    /**
     * Hora de envío/sincronización según el reloj del dispositivo (opcional).
     * Permite distinguir sincronización sin conexión tardía de reloj alterado.
     */
    synced_at?: IsoDateTime | null;
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
    /** Recepción en servidor (igual a server_time; null en registros anteriores). */
    received_at?: IsoDateTime | null;
    /** Hora de envío/sincronización declarada por el dispositivo. */
    synced_at?: IsoDateTime | null;
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
    | 'offline_not_allowed'
    | 'missing_clock_out'
    | 'missing_clock_in'
    /** Sólo histórico: salida generada por la extinta salida automática. */
    | 'auto_clock_out';

export interface PunchReceipt {
    punch: Punch;
    /** Folio verificable mostrado como comprobante digital. */
    receipt_code: string;
    workday: Workday;
}

/** POST /api/v1/sync/punches */
export interface SyncRequest {
    punches: PunchRequest[];
    /** Hora de envío del lote según el reloj del dispositivo (opcional). */
    synced_at?: IsoDateTime | null;
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
    /**
     * @deprecated La salida automática se eliminó (Revisión RH 2026-11). Siempre null;
     * se conserva opcional para apps instaladas.
     */
    auto_clock_out_at?: IsoDateTime | null;
    /** @deprecated Siempre 0: ningún tiempo registrado se descarta. */
    after_cutoff_minutes?: number;
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

/**
 * Etapa del flujo de 5 pasos (Revisión RH, pantalla 05): solicitud con evidencia →
 * autorización del jefe → firma del colaborador (app) → validación RH → aplicada.
 * `status` se conserva: pending mientras está en curso; approved = aplicada.
 */
export type RectificationStage = 'por_autorizar' | 'por_firmar' | 'por_validar' | 'aplicada' | 'rechazada';

/** Motivo de cierre de una rectificación rechazada. */
export type RectificationRejectionStage = 'por_autorizar' | 'por_firmar' | 'por_validar' | 'vencida' | 'declinada' | 'cancelada';

/** Evidencia adjunta (metadatos verificables + URL firmada temporal, 10 min, sin Bearer). */
export interface RectificationEvidence {
    id: number;
    name: string;
    mime: string;
    size: number;
    sha256: string;
    url: string;
}

/** «Registro original» / «Propuesta». */
export interface RectificationSide {
    /** Resumen que arma el servidor (p. ej. «Sin salida», «Salida 18:00»). */
    summary?: string | null;
    /** Sólo en `original`. */
    punch_uuid?: Uuid | null;
    type: PunchType | null;
    time: IsoDateTime | null;
}

export type SignatureMethod = 'pin' | 'biometric';

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
    /* ── Revisión RH (A2). El servidor siempre los envía; opcionales sólo por tolerancia con servidores anteriores. ── */
    stage?: RectificationStage;
    stage_label?: string;
    original?: RectificationSide;
    proposal?: RectificationSide;
    evidences?: RectificationEvidence[];
    /** Extemporánea: fecha posterior al cierre de nómina (sólo RH la aplica). */
    is_late?: boolean;
    authorized_at?: IsoDateTime | null;
    /** Fin del plazo de firma (3 días hábiles configurables); vencido ⇒ rechazada. */
    signature_due_at?: IsoDateTime | null;
    signature?: { signed_at: IsoDateTime; method: SignatureMethod; hash: string } | null;
    /** Texto exacto a firmar (sólo en «por_firmar»). */
    signature_statement?: string | null;
    /** SHA-256 del contenido firmado; entra en la firma HMAC biométrica (sólo en «por_firmar»). */
    signature_payload_sha256?: string | null;
    can_sign?: boolean;
    rejection_stage?: RectificationRejectionStage | null;
    rejection_reason?: string | null;
    applied_at?: IsoDateTime | null;
}

/**
 * POST /api/v1/rectifications (multipart). `evidence[]` OBLIGATORIA: 1 a 5 archivos
 * JPG/PNG/WEBP/HEIC/PDF de hasta 10 MB (se acepta `evidence` único por compatibilidad).
 * Duplicado abierto del mismo día y marcaje ⇒ 422 en `date`.
 */
export interface RectificationRequest {
    date: IsoDate;
    punch_uuid: Uuid | null;
    kind: Rectification['kind'];
    proposed_type: PunchType | null;
    proposed_time: IsoDateTime | null;
    reason: string;
}

/**
 * Firma electrónica (rectificación o documento). Con `method='biometric'` la app
 * desbloquea el secreto del dispositivo con la biometría del SO y envía
 *   device_signature = hex(HMAC_SHA256(base64decode(device_secret),
 *     "sign|{purpose}|{signable_id}|{signature_payload_sha256}|{device_uuid}"))
 * purpose: rectification | registration_agreement | privacy_notice. El dispositivo
 * lo toma el servidor del token. NIP: 5 fallos ⇒ bloqueo 15 min.
 */
export interface SignRequest {
    method: SignatureMethod;
    pin?: string;
    device_signature?: string;
    /** Informativos (el servidor usa el dispositivo del token). */
    device_uuid?: Uuid;
    device_time?: IsoDateTime;
}

/** POST /api/v1/rectifications/{id}/decline — el colaborador no acepta la corrección. */
export interface DeclineRequest {
    reason: string;
}

/** POST /api/v1/profile/signature-pin — 4 a 6 dígitos; `current_pin` obligatorio para cambiarlo. */
export interface SignaturePinRequest {
    pin: string;
    current_pin?: string;
}

export interface SignaturePinStatus {
    has_pin: boolean;
    set_at: IsoDateTime | null;
}

/* ─────────────── Convenio de registro y aviso de privacidad (A2) ─────────────── */

export type LegalDocumentCode = 'CONVENIO_REGISTRO' | 'AVISO_PRIVACIDAD';

/** GET /api/v1/documents/pending — versión vigente sin firmar. */
export interface PendingDocument {
    id: number;
    code: LegalDocumentCode;
    purpose: 'registration_agreement' | 'privacy_notice';
    title: string;
    version: string;
    body_markdown: string;
    sha256: string;
    published_at: IsoDateTime;
    /** URL firmada temporal (10 min) del PDF; se abre sin Bearer. */
    pdf_url: string;
    signature_statement: string;
    signature_payload_sha256: string;
}

/** POST /api/v1/documents/{id}/sign (SignRequest) → constancia. Idempotente. */
export interface DocumentSignResult {
    document_id: number;
    code: LegalDocumentCode;
    signed_at: IsoDateTime;
    method: SignatureMethod;
    hash: string;
}

/** GET /api/v1/documents — documentos firmados por el colaborador (todas las versiones). */
export interface SignedDocument {
    id: number;
    code: LegalDocumentCode;
    title: string;
    version: string;
    body_markdown: string | null;
    pdf_url: string;
    signed_at: IsoDateTime;
    signature_method: SignatureMethod;
    hash: string;
}

/* ──────────────────────────── Incidencias ──────────────────────────── */

/**
 * Catálogo CERRADO (Revisión RH, pantalla 06): PERMISO_CON_GOCE, PERMISO_SIN_GOCE,
 * FALTA_JUSTIFICADA, INCAPACIDAD_EG, INCAPACIDAD_RT, INCAPACIDAD_MAT, PATERNIDAD, LUTO,
 * OMISION_ENTRADA, OMISION_SALIDA. La falta injustificada no se captura.
 */
export interface IncidenceType {
    id: number;
    code: string;
    name: string;
    category: 'absence' | 'leave' | 'disability' | 'vacation' | 'time' | 'other';
    requires_evidence: boolean;
    requires_approval: boolean;
    employee_requestable: boolean;
    evidence_kind: 'imss_certificate' | 'authorized_form' | 'death_certificate' | 'proof' | null;
    evidence_label: string | null;
    /** Incapacidades: folio IMSS obligatorio. */
    requires_folio: boolean;
    /** Omisión de checada: genera una rectificación para firma (pedir `proposed_time`). */
    omission_punch_type: 'clock_in' | 'clock_out' | null;
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
    folio: string | null;
    /** Omisión de checada: rectificación generada. */
    rectification_id: number | null;
    cancellation_reason: string | null;
}

/**
 * POST /api/v1/incidences (multipart). `evidence[]` según el tipo (1 a 5 archivos);
 * `folio` para incapacidades; `proposed_time` (HH:mm) para omisiones de checada.
 */
export interface IncidenceRequest {
    incidence_type_id: number;
    starts_on: IsoDate;
    ends_on: IsoDate;
    reason: string;
    folio?: string | null;
    proposed_time?: string | null;
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
 * `{announcement_id, requires_confirmation}`, `{punch_uuid, date, type, receipt_code}` (acuse obligatorio de marcaje, kind `attendance.punch_receipt`), `{subject, subject_id, status}`, `{date, action}`,
 * `{punch_uuid, date}`, `{device_uuid, status}`.
 */
export interface AppNotification {
    id: Uuid;
    /** p. ej. `announcement`, `reminder.late_clock_in`, `reminder.clock_out`, `request.approved` (`attendance.auto_clock_out` sólo en avisos históricos), `device.revoked`. */
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
 * POST /api/v1/announcements/{id}/read — confirmación de lectura de un comunicado
 * importante (`data.requires_confirmation === '1'`). `id` = `data.announcement_id`.
 * Idempotente. 404 si el comunicado no existe, fue retirado o no te fue enviado.
 */
export interface AnnouncementReadReceipt {
    announcement_id: Uuid;
    confirmed_at: IsoDateTime;
    read_at: IsoDateTime;
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
    /** Modo del puesto (B2). */
    mode?: LocationTracking;
    /** Fase actual del seguimiento. */
    phase?: 'active' | 'paused' | 'off';
    paused_states?: TrackingPausedState[];
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
    | 'outside_window'       // sin jornada abierta (antes de la entrada o después de la salida)
    | 'tracking_disabled'    // el puesto sólo registra ubicación al marcar
    | 'on_break'             // descanso o comida registrados: seguimiento en pausa
    | 'contingency_cutoff';  // salida omitida: se superó fin de turno + margen

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
 * GET    /rectifications                → Rectification[] + PaginationMeta (?stage=)
 * POST   /rectifications                RectificationRequest (multipart, evidence[]) → Rectification (201)
 * GET    /rectifications/{id}           → Rectification (sólo propias)
 * POST   /rectifications/{id}/sign      SignRequest → Rectification
 * POST   /rectifications/{id}/decline   DeclineRequest → Rectification
 * POST   /profile/signature-pin         SignaturePinRequest → SignaturePinStatus
 * GET    /documents/pending             → PendingDocument[]
 * GET    /documents                     → SignedDocument[]
 * POST   /documents/{id}/sign           SignRequest → DocumentSignResult
 * GET    /documents/{id}/pdf            → PDF (Bearer)
 * GET    /files/documents/{id}?signature=…  → PDF (URL firmada temporal, sin Bearer)
 * GET    /files/evidence/{id}?signature=…   → evidencia propia (URL firmada temporal, sin Bearer)
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
 * POST   /announcements/{id}/read       → AnnouncementReadReceipt
 * POST   /location/points               LocationBatchRequest → LocationBatchResponse (200)
 * GET    /location/state                → LocationState
 * GET    /health                        → { status: 'ok', time: IsoDateTime } (público)
 */
export const API_VERSION = 'v1' as const;

/* ───────────────────────────── Kiosco de sucursal (B1) ───────────────────────────── */
// Autenticación: `Authorization: Bearer <token del kiosco>` (no es token de usuario).
// El colaborador se identifica en cada marcaje con número de empleado + NIP de firma
// (5 intentos fallidos ⇒ NIP bloqueado 15 min, 423 `pin_locked`).

export type KioskStatusValue = 'pending' | 'active' | 'revoked';

/** POST /api/v1/kiosk/activate — el código es de un solo uso y vence en 48 h. */
export interface KioskActivateRequest {
    activation_code: string;
    device: Pick<DeviceInfo, 'device_uuid' | 'platform' | 'os_version' | 'app_version' | 'model'>;
}

/** GET /api/v1/kiosk/status */
export interface KioskStatus {
    id: Uuid;
    name: string;
    status: KioskStatusValue;
    branch: { id: number; name: string } | null;
    organization: { id: number; name: string | null; timezone: string | null };
    activated_at: IsoDateTime | null;
    last_seen_at: IsoDateTime | null;
    server_time: IsoDateTime;
}

export interface KioskActivation {
    /** Token del kiosco. Se entrega una sola vez: guardarlo en SecureStore. */
    token: string;
    kiosk: KioskStatus;
}

/** POST /api/v1/kiosk/punches (idempotente por `client_uuid`; 201, o 200 si duplicado). */
export interface KioskPunchRequest {
    employee_number: string;
    pin: string;
    type: PunchType;
    client_uuid: Uuid;
    device_time: IsoDateTime;
    timezone?: string | null;
    /** Obligatorio si la política exige verificación facial (ver /kiosk/face/*). */
    face_verification_id?: Uuid | null;
    location?: {
        latitude: number;
        longitude: number;
        accuracy_meters?: number | null;
        captured_at?: IsoDateTime | null;
        is_mocked?: boolean | null;
    } | null;
}

export interface KioskPunchReceipt {
    id: Uuid;
    client_uuid: Uuid;
    type: PunchType;
    server_time: IsoDateTime;
    status: string;
    captured_offline: boolean;
    employee_name: string;
    duplicate: boolean;
}

/** POST /api/v1/kiosk/sync — cola offline; sin conexión no hay verificación facial. */
export interface KioskSyncRequest {
    punches: KioskPunchRequest[];
}

export interface KioskSyncResult {
    client_uuid: Uuid;
    result: 'created' | 'duplicate' | 'rejected';
    punch?: KioskPunchReceipt;
    error?: { message: string; code: ApiErrorCode | string; errors?: Record<string, string[]>; legacy_code?: ApiErrorCode };
}

export interface KioskSyncResponse {
    results: KioskSyncResult[];
}

/**
 * POST /api/v1/kiosk/face/challenge {employee_number, pin} → FaceChallenge.
 * POST /api/v1/kiosk/face/verify (multipart de FaceVerifyRequest + employee_number y pin).
 */
export interface KioskIdentifyRequest {
    employee_number: string;
    pin: string;
}
