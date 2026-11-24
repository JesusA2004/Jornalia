/**
 * Tipos de props de los módulos de Operación (panel, asistencias, solicitudes,
 * calendario, reportes, cumplimiento, integridad, auditoría, ubicación).
 *
 * Contrato con `app/Http/Controllers/Web/Operacion/**`. Fechas civiles
 * `YYYY-MM-DD`; instantes ISO-8601 en UTC (`...Z`); duraciones en minutos.
 */
import type { LaravelPaginator, SelectOption } from './app';

export type Iso = string;
export type CivilDate = string;

/** Tarjeta mínima de colaborador (OperacionScope::employeeCards). */
export type EmployeeCard = {
    id: number;
    name: string;
    number: string;
    branch_id: number | null;
    branch: string | null;
    department: string | null;
    position: string | null;
};

export type Anomaly = { code: string; label: string };

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export type ListFilters = {
    search: string;
    branch_id: string | null;
    department_id: string | null;
    employee_id: string | null;
    status: string | null;
    from: string | null;
    to: string | null;
};

export type ScopeOptions = {
    branches: SelectOption[];
    departments: SelectOption[];
    employees: SelectOption[];
};

/* ───────────────────────────── Panel ───────────────────────────── */

export type PeriodTotals = {
    scheduled_days: number;
    attended_days: number;
    punctual_days: number;
    late_days: number;
    absent_days: number;
    incomplete_days: number;
    /** Jornadas con omisión de checada (entrada o salida). */
    omission_days: number;
    worked_minutes: number;
    /** Tiempo extra registrado (posterior al horario; nunca se descarta). */
    excess_minutes: number;
    late_minutes: number;
    night_minutes: number;
    punctuality_rate: number | null;
    attendance_rate: number | null;
    punches: number;
    flagged_punches: number;
    /** Marcajes sin conexión (hora del dispositivo y del servidor). */
    offline_punches: number;
    /** Rectificaciones autorizadas que esperan la firma del colaborador. */
    to_sign: number;
};

/** Presentes contra programados de hoy por sucursal. */
export type BranchToday = {
    branch_id: number | null;
    name: string;
    scheduled: number;
    present: number;
    rate: number | null;
};

export type AlertSummary = {
    id: number;
    date: CivilDate;
    rule_code: string;
    severity: 'info' | 'warning' | 'critical';
    severity_label: string;
    message: string;
    legal_basis: string | null;
    status: string;
    employee: { id: number; name: string; number: string } | null;
};

export type DashboardMetrics = {
    period: { from: CivilDate; to: CivilDate; days: number };
    previous_period: { from: CivilDate; to: CivilDate };
    today: {
        date: CivilDate;
        active_employees: number;
        scheduled: number;
        present: number;
        absent: number;
        pending_arrival: number;
        late: number;
        open_sessions: number;
        /** Jornadas abiertas cuyo horario ya terminó (omisión en curso). */
        missing_out: number;
        on_break: number;
        flagged_punches: number;
        punches: number;
        branches: BranchToday[];
    };
    totals: PeriodTotals;
    previous: PeriodTotals;
    /** % de variación (o puntos porcentuales en *_rate); null sin base. */
    deltas: {
        worked_minutes: number | null;
        excess_minutes: number | null;
        late_days: number | null;
        absent_days: number | null;
        flagged_punches: number | null;
        attended_days: number | null;
        punctuality_rate: number | null;
        attendance_rate: number | null;
    };
    /** Horas extra de la semana, calculadas por colaborador y por semana. */
    overtime: {
        week_start: CivilDate;
        week_end: CivilDate;
        recorded_minutes: number;
        authorized_minutes: number;
        justified_minutes: number;
        pending_minutes: number;
        double_minutes: number;
        triple_minutes: number;
        over_limit_minutes: number;
        pending: number;
        employees: number;
        minors: number;
        legal_label: string;
        legal_verified: boolean;
    };
    pending: {
        /** Rectificaciones por autorizar (jefe inmediato). */
        rectifications: number;
        /** Firmas del colaborador pendientes. */
        signatures: number;
        to_validate: number;
        /** Omisiones de checada sin rectificar. */
        omissions: number;
        incidences: number;
        vacations: number;
        overtime: number;
    };
    compliance: {
        open: number;
        by_severity: { critical: number; warning: number; info: number };
        /** Índice operativo 0–100 (no es dictamen legal). */
        score: number | null;
        recent: AlertSummary[];
    };
    branches: Array<{
        branch_id: number | null;
        name: string;
        employees: number;
        attended: number;
        late: number;
        absent: number;
        worked_minutes: number;
        excess_minutes: number;
        punctuality_rate: number | null;
    }>;
    trend: Array<{
        date: CivilDate;
        attended: number;
        punctual: number;
        late: number;
        absent: number;
        worked_hours: number;
        excess_hours: number;
        punctuality_rate: number | null;
    }>;
};

export type WebPunchState = {
    allowed: boolean;
    next_type: PunchType;
    next_label: string;
    can_break: boolean;
    policy: string;
};

export type PersonalSummary = {
    employee: {
        id: number;
        name: string;
        number: string;
        branch: string | null;
        position: string | null;
    };
    today: {
        date: CivilDate;
        timezone: string;
        shift: {
            starts_at: Iso;
            ends_at: Iso;
            schedule_name: string;
            break_minutes: number;
            tolerance_minutes: number;
        } | null;
        holiday: string | null;
        events: Array<{
            type: PunchType;
            type_label: string;
            time: Iso;
            origin: 'original' | 'rectified' | 'added';
        }>;
        worked_minutes: number;
        late_minutes: number;
        status: string | null;
        status_label: string | null;
    };
    web_punch: WebPunchState;
    series: Array<{
        date: CivilDate;
        worked_hours: number;
        late_minutes: number;
        status: string | null;
        status_label: string | null;
    }>;
    period_totals: {
        worked_minutes: number;
        late_minutes: number;
        late_days: number;
        excess_minutes: number;
    };
    vacation: VacationBalance | null;
    pending: { rectifications: number; incidences: number; vacations: number };
};

export type DashboardProps = {
    mode: 'executive' | 'personal';
    filters: {
        period: 'today' | '7d' | '30d' | 'custom';
        from: CivilDate;
        to: CivilDate;
        branch_id: string | null;
    };
    branches: SelectOption[];
    metrics: DashboardMetrics | null;
    personal: PersonalSummary | null;
    timezone: string;
};

/* ─────────────────────────── Asistencias ─────────────────────────── */

export type PunchType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';

export type Workday = {
    id: number;
    employee_id: number;
    employee: EmployeeCard | null;
    date: CivilDate;
    status: string;
    status_label: string;
    shift_kind: string | null;
    shift_kind_label: string | null;
    schedule_name: string | null;
    branch: string | null;
    department: string | null;
    scheduled_start: Iso | null;
    scheduled_end: Iso | null;
    scheduled_minutes: number;
    first_in: Iso | null;
    last_out: Iso | null;
    worked_minutes: number;
    break_minutes: number;
    late_minutes: number;
    early_leave_minutes: number;
    excess_minutes: number;
    night_minutes: number;
    has_rectifications: boolean;
    /** Omisión de checada del día (el sistema nunca completa marcajes). */
    omission: 'missing_clock_out' | 'missing_clock_in' | null;
    /** Origen del marcaje (PunchOrigin). */
    origin: PunchOrigin | null;
    /** Rectificación aplicada (firmada por el colaborador) del día. */
    rectification: { id: number; url: string | null } | null;
    anomalies: Anomaly[];
    legal_version_code: string | null;
    computed_at: Iso | null;
};

export type PunchRow = {
    id: number;
    uuid: string;
    employee_id: number;
    employee?: EmployeeCard | null;
    type: PunchType;
    type_label: string;
    server_time: Iso;
    device_time: Iso | null;
    local_date: CivilDate;
    timezone: string;
    source: string;
    source_label: string;
    auth_method: string;
    auth_method_label: string;
    status: 'accepted' | 'flagged';
    status_label: string;
    captured_offline: boolean;
    signature_valid: boolean;
    anomalies: Anomaly[];
    has_location: boolean;
    sequence: number;
    /** Histórico generado por el sistema: se conserva, no se reconoce. */
    is_system: boolean;
    /** Hora reconocida por el dominio (null en históricos de sistema). */
    recognized_time: Iso | null;
    origin: PunchOrigin;
    /** Recepción en servidor (hora oficial). */
    received_at: Iso | null;
    /** Envío/sincronización según el reloj del dispositivo. */
    synced_at: Iso | null;
};

/** Origen del marcaje (Revisión RH, pantalla 03). */
export type PunchOrigin = {
    code:
        | 'app_inside'
        | 'app_outside'
        | 'app'
        | 'offline'
        | 'kiosk'
        | 'web'
        | 'import'
        | 'rectified'
        | 'omission'
        | 'system_historic';
    label: string;
};

export type PunchDetail = PunchRow & {
    clock_skew_seconds: number | null;
    latitude: string | null;
    longitude: string | null;
    accuracy_meters: number | null;
    location_captured_at: Iso | null;
    location_mocked: boolean | null;
    geofence: { id: number; name: string; radius_meters: number } | null;
    distance_meters: number | null;
    face_verified: boolean;
    device: {
        id: number;
        name: string;
        platform: string;
        model: string | null;
    } | null;
    recorded_by: string | null;
    ip_address: string | null;
    hash: string;
    previous_hash: string;
    created_at: Iso;
    /** Excluido del cálculo por una rectificación aprobada (nunca se borra). */
    voided?: boolean;
};

export type AdjustmentRow = {
    id: number;
    uuid: string;
    action: 'add' | 'void' | 'replace';
    action_label: string;
    type: PunchType | null;
    type_label: string | null;
    target_punch_uuid: string | null;
    recognized_time: Iso | null;
    local_date: CivilDate;
    reason: string;
    approved_by: string | null;
    rectification_request_id: number;
    sequence: number;
    hash: string;
    created_at: Iso;
};

export type RecognizedEvent = {
    type: PunchType;
    type_label: string;
    time: Iso;
    origin: 'original' | 'rectified' | 'added';
    origin_label: string;
    punch_uuid: string | null;
    adjustment_uuid: string | null;
};

export type DayDetail = {
    date: CivilDate;
    timezone: string;
    shift: {
        starts_at: Iso;
        ends_at: Iso;
        kind: string;
        kind_label: string;
        break_minutes: number;
        tolerance_minutes: number;
        scheduled_minutes: number;
        schedule_name: string;
        from_exception: boolean;
    } | null;
    holiday: { name: string; legal_basis: string | null; kind: string } | null;
    punches: PunchDetail[];
    adjustments: AdjustmentRow[];
    recognized: RecognizedEvent[];
};

export type AttendanceIndexProps = {
    tab: 'jornadas' | 'marcajes';
    filters: ListFilters & {
        sort: string | null;
        direction: string | null;
        /** «Sólo omisiones de checada» (combinable). */
        omisiones: boolean;
    };
    options: ScopeOptions & {
        workday_statuses: SelectOption[];
        punch_statuses: SelectOption[];
        punch_types: SelectOption[];
    };
    timezone: string;
    workdays?: LaravelPaginator<Workday>;
    punches?: LaravelPaginator<PunchRow>;
    totals: {
        days?: number;
        worked_minutes?: number;
        late_minutes?: number;
        excess_minutes?: number;
        absent_days?: number;
        late_days?: number;
        omissions?: number;
        punches?: number;
        flagged?: number;
        offline?: number;
    };
};

export type AttendanceShowProps = {
    employee: EmployeeCard | null;
    date: CivilDate;
    previous_date: CivilDate;
    next_date: CivilDate;
    summary: Workday | null;
    day: DayDetail;
    week: Array<{
        date: CivilDate;
        status: string;
        status_label: string;
        worked_minutes: number;
        late_minutes: number;
    }>;
    rectifications: Array<{
        id: number;
        kind_label: string;
        status: ApprovalStatus;
        status_label: string;
        reason: string;
        requested_by: string | null;
        created_at: Iso;
    }>;
    can: { rectify: boolean };
};

/* ───────────────────────── Aprobaciones ───────────────────────── */

export type AttachmentRow = {
    id: number;
    name: string;
    mime: string;
    size: number;
    sha256: string;
    uploaded_by: string | null;
    created_at: Iso;
    /** Descarga protegida (autorización en servidor). */
    url: string;
};

export type DecisionRow = {
    id: number;
    level: number;
    decision: 'approved' | 'rejected';
    decision_label: string;
    comment: string | null;
    decided_by: string | null;
    decided_at: Iso;
};

export type ApprovalLevel = {
    level: number;
    label: string;
    state: 'done' | 'current' | 'pending' | 'rejected' | 'cancelled';
};

export type InboxTab = 'pendientes' | 'resueltas' | 'todas';

export type InboxCounts = { pending: number; resolved: number; all: number };

/* ───────────────────────── Rectificaciones ───────────────────────── */

/** Etapas del flujo de 5 pasos (pestañas). */
export type RectificationStage =
    | 'por_autorizar'
    | 'por_firmar'
    | 'por_validar'
    | 'aplicada'
    | 'rechazada';

/** Pestañas de la bandeja (`?tab=`). */
export type RectificationTab =
    | 'por_autorizar'
    | 'por_firmar'
    | 'por_validar'
    | 'aplicadas'
    | 'rechazadas';

export type RectificationSignatureStatus =
    | 'firmada'
    | 'no_requerida'
    | 'pendiente'
    | 'vencida'
    | 'no_aceptada'
    | 'en_espera';

export type RectificationRow = {
    id: number;
    employee: EmployeeCard | null;
    date: CivilDate;
    kind: 'missing_punch' | 'wrong_type' | 'time_clarification' | 'other';
    kind_label: string;
    stage: RectificationStage;
    stage_label: string;
    status: ApprovalStatus;
    status_label: string;
    punch_uuid: string | null;
    original_summary: string;
    proposal_summary: string;
    proposed_type_label: string | null;
    proposed_time: Iso | null;
    reason_excerpt: string;
    signature_status: RectificationSignatureStatus;
    rejection_stage: string | null;
    signature_due_at: Iso | null;
    signed_at: Iso | null;
    is_late: boolean;
    requested_by: string | null;
    authorized_by: string | null;
    reviewed_by: string | null;
    reviewed_at: Iso | null;
    attachments_count: number;
    created_at: Iso;
    can_authorize: boolean;
    can_validate: boolean;
    can_apply_late: boolean;
    can_cancel: boolean;
};

export type RectificationSettings = {
    business_days: number;
    confirmed_by_hr: boolean;
    closed_through: CivilDate | null;
};

export type RectificationIndexProps = {
    tab: RectificationTab;
    requests: LaravelPaginator<RectificationRow>;
    counts: Record<RectificationTab, number>;
    filters: ListFilters;
    options: ScopeOptions & {
        kinds: SelectOption[];
        punch_types: SelectOption[];
        stages: SelectOption[];
    };
    settings: RectificationSettings;
    can: { create: boolean };
    timezone: string;
};

export type RectificationSignature = {
    method: 'pin' | 'biometric';
    method_label: string;
    signed_at: Iso | null;
    device: string | null;
    ip: string | null;
    hash: string;
    payload_sha256: string;
    statement: string;
    sealed: boolean;
};

export type RectificationShowProps = {
    request: RectificationRow & {
        reason: string;
        review_comment: string | null;
        proposed_type: PunchType | null;
        authorization_comment: string | null;
        validation_comment: string | null;
        rejection_reason: string | null;
        rejected_by: string | null;
        rejected_at: Iso | null;
        validated_by: string | null;
        validated_at: Iso | null;
        authorized_at: Iso | null;
        applied_at: Iso | null;
        legacy_without_signature: boolean;
        origin: 'employee' | 'manager' | 'incidence';
        incidence_id: number | null;
    };
    signature: RectificationSignature | null;
    target_punch: PunchDetail | null;
    attachments: AttachmentRow[];
    decisions: DecisionRow[];
    adjustments: AdjustmentRow[];
    day: DayDetail;
    timezone: string;
};

/* ─────────────────────────── Incidencias ─────────────────────────── */

export type IncidenceTypeOption = SelectOption & {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    requires_evidence: boolean;
    requires_approval: boolean;
    affects_payroll: boolean;
    employee_requestable: boolean;
    description: string | null;
    evidence_kind: string | null;
    evidence_label: string | null;
    requires_folio: boolean;
    approval_route: string | null;
    approval_route_label: string | null;
    omission_punch_type: 'clock_in' | 'clock_out' | null;
};

export type IncidenceRow = {
    id: number;
    employee: EmployeeCard | null;
    type_name: string | null;
    type_code: string | null;
    category: string | null;
    category_label: string | null;
    starts_on: CivilDate;
    ends_on: CivilDate;
    days: number;
    minutes: number | null;
    source: 'employee' | 'manager' | 'system' | 'people';
    source_label: string;
    status: ApprovalStatus;
    status_label: string;
    reason_excerpt: string;
    current_level: number;
    current_level_label: string | null;
    requested_by: string | null;
    reviewed_by: string | null;
    reviewed_at: Iso | null;
    attachments_count: number;
    created_at: Iso;
    folio: string | null;
    is_omission: boolean;
    rectification_request_id: number | null;
    can_decide: boolean;
    can_cancel: boolean;
};

export type IncidenceIndexProps = {
    tab: InboxTab;
    incidences: LaravelPaginator<IncidenceRow>;
    counts: InboxCounts;
    filters: ListFilters & { type_id: string | null };
    options: ScopeOptions & {
        types: IncidenceTypeOption[];
        statuses: SelectOption[];
    };
    evidence_kinds: Record<string, string>;
    can: { create: boolean };
    timezone: string;
};

export type IncidenceShowProps = {
    incidence: IncidenceRow & {
        reason: string | null;
        review_comment: string | null;
        type:
            | Omit<IncidenceTypeOption, 'value' | 'label'>
            | Record<string, never>;
        cancelled_by: string | null;
        cancelled_at: Iso | null;
        cancellation_reason: string | null;
        external_source: string | null;
    };
    attachments: AttachmentRow[];
    decisions: DecisionRow[];
    levels: ApprovalLevel[];
    timezone: string;
};

/* ─────────────────────────── Vacaciones ─────────────────────────── */

export type VacationBalance = {
    service_year: number;
    period_starts_on: CivilDate;
    period_ends_on: CivilDate;
    legal_days: number;
    extra_days: number;
    entitled_days: number;
    used_days: number;
    pending_days: number;
    available_days: number;
    vacation_premium_percent: string;
    /** Fundamento: "LFT art. 76 (DOF 27-12-2022)" + política. */
    rule_source: string;
};

export type VacationRow = {
    id: number;
    employee: EmployeeCard | null;
    starts_on: CivilDate;
    ends_on: CivilDate;
    days: number;
    service_year: number;
    status: ApprovalStatus;
    status_label: string;
    current_level_label: string | null;
    requested_by: string | null;
    reviewed_by: string | null;
    reviewed_at: Iso | null;
    created_at: Iso;
    can_decide: boolean;
    can_cancel: boolean;
};

export type VacationCalendarDay = {
    date: CivilDate;
    absences: Array<{
        request_id: number;
        employee_id: number;
        name: string;
        branch: string | null;
        status: ApprovalStatus;
    }>;
    conflicts: Array<{
        branch: string;
        absent: number;
        headcount: number;
        ratio: number;
        level: 'medium' | 'high';
    }>;
};

export type VacationIndexProps = {
    tab: 'solicitudes' | 'saldos' | 'calendario';
    filters: ListFilters & { month: string };
    options: ScopeOptions & { statuses: SelectOption[] };
    counts: { pending: number; approved_upcoming: number };
    can: { create: boolean };
    own_employee_id: number | null;
    timezone: string;
    requests?: LaravelPaginator<VacationRow>;
    balances?: LaravelPaginator<{
        employee: EmployeeCard | null;
        balance: VacationBalance | null;
    }>;
    calendar?: {
        month: string;
        start: CivilDate;
        end: CivilDate;
        days: VacationCalendarDay[];
        total_requests: number;
        conflict_days: number;
    };
};

export type VacationShowProps = {
    request: VacationRow & {
        comment: string | null;
        review_comment: string | null;
    };
    balance: VacationBalance | null;
    decisions: DecisionRow[];
    levels: ApprovalLevel[];
    overlaps: Array<{
        id: number;
        employee: EmployeeCard | null;
        starts_on: CivilDate;
        ends_on: CivilDate;
        status: ApprovalStatus;
        status_label: string;
    }>;
    timezone: string;
};

/* ─────────────────────────── Horas extra ─────────────────────────── */

export type OvertimeRecordCell = {
    id: number;
    date: CivilDate;
    recorded_excess_minutes: number;
    authorized_minutes: number;
    /** Revisado y no autorizado como extra (el tiempo se conserva). */
    justified_minutes: number;
    pending_minutes: number;
    double_minutes: number;
    triple_minutes: number;
    over_limit_minutes: number;
    status: ApprovalStatus;
    status_label: string;
    justification: string | null;
    authorized_by: string | null;
    authorized_at: Iso | null;
    legal_version_code: string | null;
    /** Nivel de aprobación pendiente (flujos multinivel). */
    current_level_label: string | null;
    can_decide: boolean;
    /** false para menores de 18: sólo puede justificarse. */
    can_authorize: boolean;
};

/** Totales semanales por colaborador (WeeklyOvertime::totals). */
export type OvertimeTotals = {
    worked_minutes: number;
    ordinary_minutes: number;
    recorded_minutes: number;
    double_minutes: number;
    triple_minutes: number;
    over_limit_minutes: number;
    authorized_minutes: number;
    justified_minutes: number;
    pending_minutes: number;
    overtime_days: number;
};

export type OvertimeDayCell = {
    date: CivilDate;
    worked_minutes: number;
    ordinary_minutes: number;
    recorded_minutes: number;
    double_minutes: number;
    triple_minutes: number;
    over_limit_minutes: number;
    authorized_minutes: number;
    justified_minutes: number;
    pending_minutes: number;
    minor: boolean;
    status: ApprovalStatus | null;
    record_id: number | null;
    status_label: string | null;
    record: OvertimeRecordCell | null;
};

export type OvertimeWeekRow = {
    employee: EmployeeCard;
    /** Menor de 18 años (null sin fecha de nacimiento). */
    minor: boolean | null;
    days: OvertimeDayCell[];
    totals: OvertimeTotals & { pending: number };
};

export type OvertimeIndexProps = {
    week: {
        start: CivilDate;
        end: CivilDate;
        previous: CivilDate;
        next: CivilDate;
        days: CivilDate[];
    };
    rows: LaravelPaginator<OvertimeWeekRow>;
    totals: {
        employees: number;
        overtime_days: number;
        recorded_minutes: number;
        authorized_minutes: number;
        justified_minutes: number;
        pending_minutes: number;
        double_minutes: number;
        triple_minutes: number;
        over_limit_minutes: number;
        pending: number;
        minors: number;
    };
    /** Topes de la tabla normativa vigente (Configuración › Normativa). */
    limits: {
        version: string;
        name: string | null;
        verified: boolean;
        verification_label: string;
        verified_at: Iso | null;
        weekly_ordinary_max_minutes: number;
        daily_max_minutes: number;
        weekly_max_minutes: number;
        max_days_per_week: number;
        excess_weekly_max_minutes: number;
        daily_total_max_minutes: number | null;
        double_multiplier: string;
        excess_multiplier: string;
        minors_allowed: boolean;
    };
    filters: Omit<ListFilters, 'from' | 'to'> & {
        week: CivilDate;
        all: boolean;
    };
    options: ScopeOptions & { statuses: SelectOption[] };
    can: { approve: boolean };
    timezone: string;
};

/* ─────────────────────────── Calendario ─────────────────────────── */

export type CalendarView = 'month' | 'week' | 'day';

export type CalendarDayCell = {
    date: CivilDate;
    employed: boolean;
    shift: {
        starts_at: Iso;
        ends_at: Iso;
        kind: string;
        kind_label: string;
        break_minutes: number;
        schedule_name: string;
        from_exception: boolean;
    } | null;
    rest_day: boolean;
    no_schedule: boolean;
    holiday: {
        name: string;
        legal_basis: string | null;
        is_mandatory_rest?: boolean;
    } | null;
    vacation: {
        id: number;
        status: ApprovalStatus;
        status_label: string;
    } | null;
    incidence: {
        id: number;
        name: string | null;
        category: string | null;
        status: ApprovalStatus;
        status_label: string;
    } | null;
    status: string | null;
    status_label: string | null;
    worked_minutes: number;
};

export type CalendarEvent = {
    id: string;
    kind: 'vacation' | 'leave' | 'disability' | 'incidence';
    kind_label: string;
    title: string;
    subtitle: string | null;
    starts_on: CivilDate;
    ends_on: CivilDate;
    status: ApprovalStatus;
    status_label: string;
    href: string;
};

export type CalendarHoliday = {
    id: number;
    date: CivilDate;
    code?: string;
    name: string;
    kind: string;
    kind_label: string;
    is_mandatory_rest: boolean;
    legal_basis: string | null;
};

export type CalendarProps = {
    view: CalendarView;
    range: {
        start: CivilDate;
        end: CivilDate;
        anchor: CivilDate;
        previous: CivilDate;
        next: CivilDate;
        today: CivilDate;
        days: CivilDate[];
    };
    /** null en vista mes sin colaborador seleccionado. */
    roster: LaravelPaginator<{
        employee: EmployeeCard | null;
        days: CalendarDayCell[];
        /** Descanso semanal según horario vigente (p. ej. ['domingo']). */
        rest_days?: string[];
    }> | null;
    events: CalendarEvent[];
    holidays: CalendarHoliday[];
    filters: Pick<
        ListFilters,
        'search' | 'branch_id' | 'department_id' | 'employee_id'
    > & { view: CalendarView; date: CivilDate };
    options: ScopeOptions;
    timezone: string;
};

/* ─────────────────────────── Reportes ─────────────────────────── */

export type ReportColumnType =
    | 'text'
    | 'date'
    | 'datetime'
    | 'time'
    | 'minutes'
    | 'number'
    | 'percent'
    | 'status'
    | 'hash'
    | 'decimal';

export type ReportColumn = {
    key: string;
    label: string;
    type: ReportColumnType;
    sum?: boolean;
};

export type ReportDefinition = {
    key: string;
    title: string;
    description: string;
    category: string;
    category_label: string;
    landscape: boolean;
    columns: ReportColumn[];
    status_options?: SelectOption[];
    requires_employee?: boolean;
    /** Filtros aplicables: branch, department, position, region, employee, status, period_unit. */
    filters?: string[];
    max_days?: number | null;
};

export type ReportCatalogItem = {
    key: string;
    title: string;
    description: string;
    category: string;
    category_label: string;
    columns: number;
    featured: boolean;
    requires_employee?: boolean;
};

export type ReportExportRow = {
    id: number;
    uuid: string;
    folio?: string | null;
    report: string;
    title: string;
    format: 'xlsx' | 'pdf' | 'csv';
    status: 'queued' | 'processing' | 'completed' | 'failed';
    row_count: number | null;
    sha256: string | null;
    error: string | null;
    parameters: Record<string, unknown>;
    created_at: Iso;
    completed_at: Iso | null;
    download_url: string | null;
};

export type ReportIndexProps = {
    catalog: ReportCatalogItem[];
    exports: ReportExportRow[];
    can: { export: boolean };
    timezone: string;
};

export type ReportShowProps = {
    report: ReportDefinition;
    rows: LaravelPaginator<Record<string, string | number | null>>;
    /** Totales de columnas `sum` (vacío si el volumen es muy grande). */
    totals: Record<string, number | null>;
    /** La vista previa se detuvo en el límite de filas: totales sólo en el archivo. */
    capped?: boolean;
    /** El reporte exige elegir un colaborador. */
    needs_employee?: boolean;
    filters: Omit<ListFilters, 'search'> & {
        position_id?: string | null;
        region?: string | null;
        period_unit?: string | null;
    };
    options: ScopeOptions & {
        statuses: SelectOption[];
        positions?: SelectOption[];
        regions?: SelectOption[];
        period_units?: SelectOption[];
    };
    exports: ReportExportRow[];
    can: { export: boolean };
    timezone: string;
};

/* ─────────────────────────── Cumplimiento ─────────────────────────── */

export type ComplianceAlertRow = {
    id: number;
    employee: EmployeeCard | null;
    date: CivilDate;
    rule_code: string;
    rule_label: string;
    severity: 'info' | 'warning' | 'critical';
    severity_label: string;
    message: string;
    legal_basis: string | null;
    data: Record<string, unknown> | null;
    status: 'open' | 'acknowledged' | 'resolved';
    status_label: string;
    resolved_by: string | null;
    resolved_at: Iso | null;
    resolution_note: string | null;
    created_at: Iso;
    can_resolve: boolean;
};

export type LegalSummary = {
    code: string;
    name: string | null;
    valid_from: CivilDate | null;
    valid_to: CivilDate | null;
    verification_status: string;
    verified: boolean;
    verified_by: string | null;
    verified_at: Iso | null;
    notes: string | null;
    sources: Array<{ title: string; url?: string; published_on?: string }>;
    parameters: {
        overtime_excess_weekly_max: number;
        daily_total_max: number | null;
        weekly_ordinary_max_minutes: number;
        daily_max_day: number;
        daily_max_night: number;
        daily_max_mixed: number;
        night_start: string;
        night_end: string;
        min_break_minutes: number;
        overtime_daily_max: number;
        overtime_weekly_max: number;
        overtime_max_days_per_week: number;
        overtime_double_multiplier: string;
        overtime_excess_multiplier: string;
        rest_days_per_week: number;
        sunday_premium_min_percent: string;
        vacation_premium_min_percent: string;
    };
};

export type ComplianceIndexProps = {
    alerts: LaravelPaginator<ComplianceAlertRow>;
    summary: {
        open: number;
        by_severity: { critical: number; warning: number; info: number };
        by_rule: Array<{ rule: string; label: string; total: number }>;
    };
    legal: LegalSummary | null;
    /** Resumen de integridad (sólo con `integrity.view`). */
    integrity: {
        valid: boolean | null;
        last_verification_at: Iso | null;
        last_anchor_at: Iso | null;
        punches: number;
        adjustments: number;
    } | null;
    filters: {
        search: string;
        branch_id: string | null;
        employee_id: string | null;
        status: 'open' | 'acknowledged' | 'resolved' | 'all';
        severity: string | null;
        rule: string | null;
        from: string | null;
        to: string | null;
    };
    options: {
        branches: SelectOption[];
        employees: SelectOption[];
        severities: SelectOption[];
        rules: SelectOption[];
        statuses: SelectOption[];
    };
    can: { resolve: boolean };
    timezone: string;
};

/* ─────────────────────────── Integridad ─────────────────────────── */

export type ChainVerification = {
    chain: 'punches' | 'adjustments';
    label: string;
    checked: number;
    valid: boolean;
    head_sequence: number;
    head_hash: string;
    errors: Array<{ sequence: number; error: string }>;
    errors_total: number;
    anchors_checked: number;
};

export type IntegrityIndexProps = {
    chains: Array<{
        chain: 'punches' | 'adjustments';
        label: string;
        records: number;
        head_sequence: number;
        head_hash: string | null;
        last_record_at: Iso | null;
        anchored_sequence: number;
        last_anchor_at: Iso | null;
        unanchored: number;
    }>;
    anchors: LaravelPaginator<{
        id: number;
        chain: string;
        chain_label: string;
        sequence: number;
        hash: string;
        method: string;
        external_reference: string | null;
        /** null si no hay clave de anclaje configurada. */
        signature_valid: boolean | null;
        created_at: Iso;
    }>;
    latest: IntegrityVerificationLog | null;
    history: IntegrityVerificationLog[];
    /** Verificación y anclaje automáticos diarios. */
    automation: {
        schedule: string;
        last_anchor: {
            at: Iso;
            ok: boolean;
            automatic: boolean;
            error: string | null;
        } | null;
        anchor_key_configured: boolean;
        anchor_storage: { disk: string; driver: string; external: boolean };
    };
    can: { verify: boolean };
    timezone: string;
};

export type IntegrityVerificationLog = {
    id: number;
    at: Iso;
    by: string | null;
    automatic: boolean;
    valid: boolean;
    chains: ChainVerification[];
};

/* ─────────────────────────── Auditoría ─────────────────────────── */

export type AuditLogRow = {
    id: number;
    action: string;
    module: string;
    module_label: string;
    description: string;
    subject_type: string | null;
    subject_id: number | null;
    context: Record<string, unknown> | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    device_id: number | null;
    device: string | null;
    user: { id: number; name: string; email: string } | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: Iso;
};

export type AuditIndexProps = {
    logs: LaravelPaginator<AuditLogRow>;
    filters: {
        search: string;
        module: string | null;
        user_id: string | null;
        from: string | null;
        to: string | null;
    };
    options: { modules: SelectOption[]; users: SelectOption[] };
    timezone: string;
};

/* ─────────────────────────── Ubicación ─────────────────────────── */

/** Reglas de uso de la ubicación (Configuración › Ubicación). */
export type LocationTrackingSettings = {
    retention_days: number;
    contingency_minutes: number;
    retention_confirmed: boolean;
};

export type LiveMarker = {
    employee: EmployeeCard | null;
    employee_id: number;
    latitude: number;
    longitude: number;
    accuracy_m: number | null;
    speed_mps: number | null;
    heading: number | null;
    is_mocked: boolean;
    app_state: string;
    captured_at: Iso;
    on_shift: boolean;
    last_punch: { type: PunchType; type_label: string; at: Iso } | null;
    state: 'on_shift' | 'stale' | 'off_shift';
    state_label: string;
};

export type LocationTrack = {
    employee: EmployeeCard | null;
    date: CivilDate;
    points: Array<{
        latitude: number;
        longitude: number;
        accuracy_m: number | null;
        speed_mps: number | null;
        is_mocked: boolean;
        captured_at: Iso;
    }>;
    punches: Array<
        PunchRow & {
            latitude: number | null;
            longitude: number | null;
            accuracy_meters: number | null;
            geofence: string | null;
        }
    >;
    summary: {
        points: number;
        distance_m: number;
        first_at: Iso | null;
        last_at: Iso | null;
        mocked: number;
    };
};

export type BranchPin = {
    id: number;
    name: string;
    latitude: number;
    longitude: number;
    geofences: Array<{
        name: string;
        latitude: number | null;
        longitude: number | null;
        radius_meters: number;
    }>;
};

export type LocationIndexProps = {
    markers: LiveMarker[];
    track: LocationTrack | null;
    branches: BranchPin[];
    filters: {
        search: string;
        branch_id: string | null;
        status: 'on_shift' | 'stale' | 'off_shift' | null;
        employee_id: string | null;
        date: CivilDate;
    };
    options: {
        branches: SelectOption[];
        employees: SelectOption[];
        statuses: SelectOption[];
    };
    stale_minutes: number;
    tracking: LocationTrackingSettings;
    server_time: Iso;
    timezone: string;
};

/** Marcaje del día para el selector del formulario de rectificación (GET rectificaciones.punches). */
export type DayPunchOption = {
    uuid: string;
    type: PunchType;
    type_label: string;
    /** Hora reconocida (ISO UTC). */
    time: Iso;
    server_time: Iso;
    source_label: string;
    auth_method_label: string;
    status: 'accepted' | 'flagged';
    status_label: string;
    is_system: boolean;
    voided: boolean;
    anomalies: Anomaly[];
};

export type DayPunchesResponse = {
    date: CivilDate;
    timezone: string;
    shift: DayDetail['shift'];
    punches: DayPunchOption[];
};
