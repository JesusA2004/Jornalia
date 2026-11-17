/**
 * Tipos de props de las páginas de /colaboradores (EmployeeController,
 * EmploymentController, EmployeeAccountController, EmployeeImportController).
 */
import type { LaravelPaginator, SelectOption } from '@/types';

export type EmployeeStatus = 'active' | 'inactive' | 'terminated';

export type EmployeeBase = {
    id: number;
    employee_number: string;
    first_name: string;
    last_name: string;
    second_last_name: string | null;
    full_name: string;
    email: string | null;
    phone: string | null;
    status: EmployeeStatus;
    has_account: boolean;
};

export type EmployeeListRow = EmployeeBase & {
    branch: string | null;
    department: string | null;
    position: string | null;
    schedule: string | null;
    starts_on: string | null;
    ends_on: string | null;
};

export type EmployeeFilters = {
    search?: string | null;
    status?: string | null;
    branch_id?: string | null;
    department_id?: string | null;
    position_id?: string | null;
    account?: string | null;
    sort?: string | null;
    direction?: 'asc' | 'desc' | null;
};

export type EmployeeIndexProps = {
    employees: LaravelPaginator<EmployeeListRow>;
    filters: EmployeeFilters;
    stats: {
        active: number;
        terminated: number;
        without_account: number;
        hired_this_month: number;
    };
    options: {
        branches: SelectOption[];
        departments: SelectOption[];
        positions: SelectOption[];
    };
    can: { create: boolean; import: boolean };
};

export type LinkedOption = SelectOption & {
    work_center_id?: string | null;
    department_id?: string | null;
};

export type EmployeeFormOptions = {
    legal_entities: SelectOption[];
    work_centers: SelectOption[];
    branches: LinkedOption[];
    departments: SelectOption[];
    positions: LinkedOption[];
    schedules: SelectOption[];
    attendance_policies: SelectOption[];
    supervisors: SelectOption[];
    contract_types: SelectOption[];
    work_modalities: SelectOption[];
};

export type EmployeeCreateProps = {
    options: EmployeeFormOptions;
    suggested_number: string;
    can: { sensitive: boolean };
};

export type Assignment = {
    id: number;
    effective_from: string;
    effective_to: string | null;
    work_center: string | null;
    work_center_id: number | null;
    branch: string | null;
    branch_id: number | null;
    department: string | null;
    department_id: number | null;
    position: string | null;
    position_id: number | null;
    schedule: string | null;
    schedule_id: number | null;
    supervisor: string | null;
    supervisor_employee_id: number | null;
    attendance_policy: string | null;
    attendance_policy_id: number | null;
    auto_clock_out_at: string | null;
    reason: string | null;
    created_by: string | null;
    created_at: string | null;
};

export type Employment = {
    id: number;
    starts_on: string;
    ends_on: string | null;
    seniority_starts_on: string;
    contract_type: string | null;
    work_modality: string | null;
    status: string;
    termination_reason: string | null;
    legal_entity: string | null;
    assignments: Assignment[];
};

export type ScheduleSegment = {
    day_index: number;
    ordinal: number;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
};

export type CurrentSchedule = {
    id: number;
    name: string;
    code: string;
    type: string;
    shift_kind: 'day' | 'night' | 'mixed';
    cycle_length_days: number;
    tolerance_minutes: number;
    weekly_minutes: number;
    segments: ScheduleSegment[];
};

export type WeekDay = {
    date: string;
    starts_at: string | null;
    ends_at: string | null;
    break_minutes: number | null;
    kind: 'day' | 'night' | 'mixed' | null;
    from_exception: boolean;
    holiday: string | null;
};

export type ScheduleExceptionRow = {
    id: number;
    date: string;
    is_rest_day: boolean;
    starts_at: string | null;
    ends_at: string | null;
    break_minutes: number;
    reason: string;
};

export type EmployeeDevice = {
    id: number;
    name: string;
    platform: string;
    model: string | null;
    app_version: string | null;
    status: 'pending' | 'approved' | 'revoked';
    is_corporate: boolean;
    last_seen_at: string | null;
    approved_at: string | null;
    revoked_at: string | null;
    revoke_reason: string | null;
};

export type VacationBalance = {
    service_year: number;
    period_starts_on: string;
    period_ends_on: string;
    legal_days: number;
    extra_days: number;
    entitled_days: number;
    used_days: number;
    pending_days: number;
    available_days: number;
    vacation_premium_percent: string;
    rule_source: string;
};

export type EmployeeAccount = {
    id: number;
    name: string;
    email: string;
    status: string;
    last_login_at: string | null;
    email_verified: boolean;
    roles: string[];
};

export type HistoryEntry = {
    id: number;
    action: string;
    description: string;
    at: string;
    actor: string | null;
};

export type EmployeeShowProps = {
    employee: EmployeeBase & {
        birth_date: string | null;
        external_source: string | null;
        external_id: string | null;
        external_synced_at: string | null;
        has_identifiers: { curp: boolean; rfc: boolean; nss: boolean };
        created_at: string | null;
    };
    current: Assignment | null;
    auto_clock_out: AutoClockOut;
    timezone: string;
    employments: Employment[];
    schedule: CurrentSchedule | null;
    week: WeekDay[];
    exceptions: ScheduleExceptionRow[];
    devices: EmployeeDevice[];
    vacations: VacationBalance | null;
    vacations_error: string | null;
    account: EmployeeAccount | null;
    history: HistoryEntry[];
    /** Registro facial activo (sin plantilla). */
    face: { enrolled: boolean; enrolled_at: string | null };
    options: EmployeeFormOptions;
    can: {
        update: boolean;
        terminate: boolean;
        rehire: boolean;
        move: boolean;
        sensitive: boolean;
        account: boolean;
        schedule: boolean;
        devices: boolean;
    };
    flash_password: string | null;
};

export type Identifiers = {
    curp: string | null;
    rfc: string | null;
    nss: string | null;
};

export type ImportConflict = {
    line: number | null;
    employee_number: string | null;
    external_id: string | null;
    name: string | null;
    type: string;
    message: string;
};

export type IntegrationRunStats = {
    total?: number;
    created?: number;
    updated?: number;
    unchanged?: number;
    conflicts?: number;
    dry_run?: boolean;
};

export type IntegrationRunData = {
    id: number;
    source: string;
    kind: string;
    status: string;
    stats: IntegrationRunStats;
    started_at: string;
    finished_at: string | null;
    started_by: string | null;
    conflicts: ImportConflict[];
    conflicts_count: number;
};

export type EmployeeImportProps = {
    runs: IntegrationRunData[];
    run: IntegrationRunData | null;
    people: { available: boolean };
    columns: Array<{ key: string; header: string; required: boolean }>;
};

export type AssignmentModel = {
    work_center_id: string | null;
    branch_id: string | null;
    department_id: string | null;
    position_id: string | null;
    supervisor_employee_id: string | null;
    schedule_id: string | null;
    attendance_policy_id: string | null;
    /** Salida automática personal HH:MM (null = hereda de puesto o política). */
    auto_clock_out_at?: string | null;
};

export type AssignmentField = keyof AssignmentModel;

/** Salida automática vigente y su origen (persona > puesto > política). */
export type AutoClockOut = {
    time: string | null;
    source: 'person' | 'position' | 'policy' | null;
    label: string | null;
    warning: string | null;
};
