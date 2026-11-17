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
    created_at: string | null;
};

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
    /** Salida automática general HH:MM (null = sin cierre). */
    auto_clock_out_at: string | null;
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

export type IncidenceTypeRow = {
    id: number;
    code: string;
    name: string;
    category: string;
    description: string | null;
    operational_effect: string;
    requires_evidence: boolean;
    requires_approval: boolean;
    affects_payroll: boolean;
    employee_requestable: boolean;
    is_system: boolean;
    active: boolean;
    valid_from: string | null;
    valid_to: string | null;
    incidences_count: number;
    deleted: boolean;
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
    is_mandatory_rest: boolean;
    legal_basis: string | null;
    work_center_id: number | null;
    work_center: string | null;
    state: string | null;
    editable: boolean;
    created_by: string | null;
    deleted: boolean;
};

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
};

export type LegalSource = {
    title?: string;
    name?: string;
    url?: string;
    reference?: string;
    [key: string]: unknown;
};

export type LegalVersionRow = {
    id: number;
    code: string;
    name: string;
    valid_from: string;
    valid_to: string | null;
    verification_status: string;
    is_current: boolean;
    checksum_ok: boolean;
    notes: string | null;
    sources: Array<LegalSource | string>;
    parameters: {
        weekly_ordinary_max_minutes: number;
        daily_max_minutes: Record<string, number>;
        night_window: Record<string, string>;
        overtime: Record<string, unknown>;
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
