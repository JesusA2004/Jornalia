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
    worked_minutes: number;
    excess_minutes: number;
    late_minutes: number;
    night_minutes: number;
    /** Tiempo posterior a salidas automáticas (conservado, no reconocido). */
    after_cutoff_minutes: number;
    /** Salidas automáticas por política registradas en el periodo. */
    auto_clock_outs: number;
    punctuality_rate: number | null;
    attendance_rate: number | null;
    punches: number;
    flagged_punches: number;
    offline_punches: number;
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
        on_break: number;
        flagged_punches: number;
        punches: number;
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
    overtime: {
        records: number;
        recorded_minutes: number;
        authorized_minutes: number;
        double_minutes: number;
        triple_minutes: number;
        over_limit_minutes: number;
        pending: number;
    };
    pending: {
        incidences: number;
        rectifications: number;
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
    /** Hora límite aplicada por salida automática (null si no hubo). */
    auto_clock_out_at: Iso | null;
    /** Minutos después del cierre: se conservan, no cuentan como jornada ni extra. */
    after_cutoff_minutes: number;
    has_rectifications: boolean;
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
    /** Registrado por el sistema (salida automática por política). */
    is_system: boolean;
    /** Hora reconocida por el dominio (en salida automática = hora límite). */
    recognized_time: Iso;
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
    auto_clock_out: {
        punch_uuid: string;
        /** Hora límite reconocida. */
        cutoff_at: Iso | null;
        /** Cuándo lo registró el sistema. */
        registered_at: Iso | null;
        /** Salida real del colaborador posterior al límite (si existe). */
        real_out_at: Iso | null;
        real_out_uuid: string | null;
        after_cutoff_minutes: number;
    } | null;
};

export type AttendanceIndexProps = {
    tab: 'jornadas' | 'marcajes';
    filters: ListFilters & {
        sort: string | null;
        direction: string | null;
        auto: boolean;
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
        auto_clock_outs?: number;
        after_cutoff_minutes?: number;
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

export type RectificationRow = {
    id: number;
    employee: EmployeeCard | null;
    date: CivilDate;
    kind: 'missing_punch' | 'wrong_type' | 'time_clarification' | 'other';
    kind_label: string;
    status: ApprovalStatus;
    status_label: string;
    punch_uuid: string | null;
    proposed_type_label: string | null;
    proposed_time: Iso | null;
    reason_excerpt: string;
    current_level: number;
    current_level_label: string | null;
    requested_by: string | null;
    reviewed_by: string | null;
    reviewed_at: Iso | null;
    attachments_count: number;
    created_at: Iso;
    can_decide: boolean;
    can_cancel: boolean;
};

export type RectificationIndexProps = {
    tab: InboxTab;
    requests: LaravelPaginator<RectificationRow>;
    counts: InboxCounts;
    filters: ListFilters;
    options: ScopeOptions & {
        kinds: SelectOption[];
        punch_types: SelectOption[];
        statuses: SelectOption[];
    };
    can: { create: boolean };
    timezone: string;
};

export type RectificationShowProps = {
    request: RectificationRow & {
        reason: string;
        review_comment: string | null;
        proposed_type: PunchType | null;
    };
    target_punch: PunchDetail | null;
    attachments: AttachmentRow[];
    decisions: DecisionRow[];
    levels: ApprovalLevel[];
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
    source: 'employee' | 'manager' | 'system';
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
};

export type OvertimeWeekRow = {
    employee: EmployeeCard;
    days: Array<{
        date: CivilDate;
        worked_minutes: number;
        ordinary_minutes: number;
        /** Después del cierre automático: no reconocido, no es extra. */
        after_cutoff_minutes: number;
        auto_clock_out_at: Iso | null;
        status_label: string | null;
        record: OvertimeRecordCell | null;
    }>;
    totals: {
        worked_minutes: number;
        ordinary_minutes: number;
        recorded_excess_minutes: number;
        authorized_minutes: number;
        double_minutes: number;
        triple_minutes: number;
        over_limit_minutes: number;
        pending: number;
        excess_days: number;
        after_cutoff_minutes: number;
    };
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
        records: number;
        recorded_excess_minutes: number;
        authorized_minutes: number;
        double_minutes: number;
        triple_minutes: number;
        over_limit_minutes: number;
        pending: number;
        after_cutoff_minutes: number;
    };
    limits: {
        version: string;
        verification_status: string;
        daily_max_minutes: number;
        weekly_max_minutes: number;
        max_days_per_week: number;
        double_multiplier: string;
        excess_multiplier: string;
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
    holiday: { name: string; legal_basis: string | null } | null;
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
    | 'hash';

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
};

export type ReportCatalogItem = {
    key: string;
    title: string;
    description: string;
    category: string;
    category_label: string;
    columns: number;
    featured: boolean;
};

export type ReportExportRow = {
    id: number;
    uuid: string;
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
    totals: Record<string, number>;
    filters: Omit<ListFilters, 'search'>;
    options: ScopeOptions & { statuses: SelectOption[] };
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
    notes: string | null;
    sources: Array<{ title: string; url?: string; published_on?: string }>;
    parameters: {
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
    can: { verify: boolean };
    timezone: string;
};

export type IntegrityVerificationLog = {
    id: number;
    at: Iso;
    by: string | null;
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
