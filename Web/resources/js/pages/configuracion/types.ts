/** Tipos de props de las páginas de /configuracion. */
import type { SelectOption } from '@/types';

export type ManageCan = { manage: boolean };

export type CompanyOrganization = {
    id: number;
    name: string;
    slug: string;
    timezone: string;
    privacy_contact_email: string | null;
    brand_primary_color: string | null;
    logo_url: string | null;
    official_logo_url: string;
    created_at: string | null;
};

export type CompanyLegalEntity = {
    id: number | null;
    legal_name: string | null;
    rfc: string | null;
    fiscal_address: string | null;
    count: number;
};

export type CompanyPrivacyNotice = {
    id: number;
    version: string;
    published_at: string | null;
    has_pdf: boolean;
} | null;

export type CompanySummary = {
    legal_entities: number;
    work_centers: number;
    branches: number;
    employees: number;
};

export type AttendancePolicyRow = {
    id: number;
    name: string;
    location_mode: string;
    allowed_auth_methods: string[];
    require_strong_biometric: boolean;
    allow_offline: boolean;
    offline_max_hours: number;
    face_verification_required: boolean;
    web_punch_allowed: boolean;
    max_clock_skew_seconds: number;
    min_location_accuracy_meters: number;
    is_default: boolean;
    deleted: boolean;
    geofence_ids: string[];
    geofences: Array<{ name: string; radius_meters: number }>;
    assigned: number;
};

export type ApprovalLevel = {
    level: number;
    permission: string;
    label: string;
};

export type ApprovalFlowRow = {
    subject: 'rectification' | 'incidence' | 'vacation' | 'overtime';
    label: string;
    description: string;
    levels: ApprovalLevel[];
    configured: boolean;
    default_permission: string;
};

export type OvertimePolicy = {
    requires_prior_authorization: boolean;
    min_minutes: number;
    allow_employee_request: boolean;
};

/** Tipo del catálogo CERRADO de incidencias (Configuración › Incidencias). */
export type IncidenceTypeRow = {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    description: string | null;
    requires_evidence: boolean;
    evidence_kind: string | null;
    requires_folio: boolean;
    approval_route: string;
    employee_requestable: boolean;
    external_code: string | null;
    is_omission: boolean;
    active: boolean;
    incidences_count: number;
};

export type RectificationParameters = {
    business_days: number;
    confirmed_by_hr: boolean;
    closed_through: string | null;
};

export type PeopleIncidenceStatus = {
    configured: boolean;
    last_success_at: string | null;
    last_run_status: string | null;
};

export type ExtraDaysRow = {
    from_year: number;
    to_year: number | null;
    days: number;
};

export type VacationPolicyRow = {
    id: number;
    name: string;
    extra_days: ExtraDaysRow[];
    premium_percent: string;
    min_notice_days: number;
    allow_half_days: boolean;
    valid_from: string;
    valid_to: string | null;
    is_default: boolean;
    deleted: boolean;
};

export type LegalVacationRow = {
    from_year: number;
    to_year: number | null;
    days: number;
    source: string;
};

export type HolidayRow = {
    id: number;
    date: string;
    code: string;
    name: string;
    kind: 'official' | 'company' | 'local';
    /** preloaded = art. 74 precargado; adjusted = ajuste de RH a un precargado; variable = obligatorio capturado por RH. */
    origin: 'preloaded' | 'adjusted' | 'variable' | 'company' | 'local';
    is_mandatory_rest: boolean;
    legal_basis: string | null;
    notes: string | null;
    change_reason: string | null;
    /** Día oficial original cuando RH lo ajustó o lo movió. */
    original: { date: string; name: string } | null;
    work_center_id: number | null;
    work_center: string | null;
    state: string | null;
    editable: boolean;
    can_deactivate: boolean;
    created_by: string | null;
    updated_by: string | null;
    deleted: boolean;
};

export type HolidayCan = ManageCan & { manage_mandatory: boolean };

export type PolicyDocumentRow = {
    id: number;
    code: string;
    title: string;
    version: string;
    body_markdown: string;
    sha256: string;
    requires_acknowledgement: boolean;
    published_at: string | null;
    created_at: string | null;
    created_by: string | null;
    acknowledgements_count: number;
    deleted: boolean;
    file_name: string | null;
    file_sha256: string | null;
    file_size: number | null;
    file_url: string | null;
};

export type LegalSource = {
    title?: string;
    name?: string;
    url?: string;
    reference?: string;
    [key: string]: unknown;
};

export type LegalEditableKey =
    | 'weekly_ordinary_max_minutes'
    | 'daily_max_day'
    | 'daily_max_night'
    | 'daily_max_mixed'
    | 'overtime_daily_max'
    | 'overtime_weekly_max'
    | 'overtime_max_days_per_week'
    | 'overtime_excess_weekly_max'
    | 'daily_total_max';

export type LegalVerification = {
    code: string | null;
    name: string | null;
    valid_from: string | null;
    valid_to: string | null;
    verified: boolean;
    label: string;
    verified_by: string | null;
    verified_at: string | null;
};

export type LegalRevisionRow = {
    id: number;
    version: string | null;
    action: string;
    action_label: string;
    user: string | null;
    note: string | null;
    changes: { field: string; before: unknown; after: unknown }[];
    created_at: string;
};

export type LegalVersionRow = {
    id: number;
    code: string;
    name: string;
    year: number;
    valid_from: string;
    valid_to: string | null;
    verification_status: string;
    verified: boolean;
    verified_by: string | null;
    verified_at: string | null;
    updated_by: string | null;
    is_editable: boolean;
    values: Record<LegalEditableKey, number | null>;
    is_current: boolean;
    checksum_ok: boolean;
    notes: string | null;
    sources: Array<LegalSource | string>;
    parameters: {
        weekly_ordinary_max_minutes: number;
        daily_max_minutes: Record<string, number>;
        night_window: Record<string, string>;
        overtime: Record<string, unknown>;
        daily_total_max_minutes: number | null;
        min_break_minutes: number | null;
        rest_days_per_week: number | null;
        sunday_premium_min_percent: string | number | null;
        vacation_premium_min_percent: string | number | null;
    };
    raw: Record<string, unknown>;
};

export type LegalVacationTableRow = {
    code: string;
    valid_from: string;
    valid_to: string | null;
    from_year: number;
    to_year: number | null;
    days: number;
    source: string;
};

export type Option = SelectOption;

/* ── Nómina: exportación NOI y pausa de comida ── */
export type NoiConcept = { code: string; label: string; enabled: boolean };

export type NoiColumn = { key: string; label: string };

export type NoiSettings = {
    date_format: string;
    decimal_separator: string;
    csv_institutional_header: boolean;
    columns: NoiColumn[];
    concepts: Record<string, NoiConcept>;
    incidence_types: Record<string, string>;
    only_payroll_incidences: boolean;
};

export type MealPauseSettings = {
    pause: number;
    confirmed_by_hr: boolean;
    /** false = aún sin ajuste propio (valor inicial del servidor). */
    configured: boolean;
    max: number;
};

export type PayrollIncidenceType = {
    code: string;
    name: string;
    affects_payroll: boolean;
};

export type SettingUpdate = { by: string | null; at: string | null } | null;
