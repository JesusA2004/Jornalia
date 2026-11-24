import type { SelectOption } from '@/types';

/** Props de `organizacion/Index` (OrganizationController@index). */

export type LegalEntityRow = {
    id: number;
    legal_name: string;
    rfc: string | null;
    employer_registration: string | null;
    fiscal_address: string | null;
    active: boolean;
    deleted: boolean;
    work_centers_count: number;
};

export type WorkCenterRow = {
    id: number;
    name: string;
    code: string;
    address: string | null;
    state_code: string | null;
    state: string | null;
    timezone: string;
    active: boolean;
    deleted: boolean;
    legal_entity_id: number | null;
    legal_entity: string | null;
    branches_count: number;
    employees_count: number;
};

export type BranchRow = {
    id: number;
    name: string;
    code: string;
    region: string | null;
    address: string | null;
    latitude: string | null;
    longitude: string | null;
    /** Registro patronal IMSS del centro de trabajo (null = pendiente). */
    employer_registration: string | null;
    /** Identificador de Mr. Lana People (null = sólo local). */
    external_id: string | null;
    active: boolean;
    deleted: boolean;
    work_center_id: number | null;
    work_center: string | null;
    geofences_count: number;
    employees_count: number;
};

export type DepartmentRow = {
    id: number;
    name: string;
    code: string;
    active: boolean;
    deleted: boolean;
    parent_id: number | null;
    parent: string | null;
    external_id: string | null;
    positions_count: number;
    employees_count: number;
};

export type PositionRow = {
    id: number;
    name: string;
    code: string;
    active: boolean;
    deleted: boolean;
    department_id: number | null;
    department: string | null;
    employees_count: number;
    external_id: string | null;
};

export type GeofenceRow = {
    id: number;
    name: string;
    latitude: string;
    longitude: string;
    radius_meters: number;
    active: boolean;
    deleted: boolean;
    branch_id: number | null;
    branch: string | null;
};

export type OrganizationPageProps = {
    tab: string;
    legal_entities: LegalEntityRow[];
    work_centers: WorkCenterRow[];
    branches: BranchRow[];
    departments: DepartmentRow[];
    positions: PositionRow[];
    geofences: GeofenceRow[];
    states: SelectOption[];
    timezones: SelectOption[];
    /** Comparación con los datos oficiales (simulación, sin cambios). */
    official: {
        status: 'applied' | 'unchanged' | 'conflict' | 'skipped';
        changes: { field: string; before: string | null; after: string }[];
        conflicts: {
            field: string;
            current: string | null;
            official: string;
        }[];
        message: string;
    };
    people: {
        configured: boolean;
        last_run: { status: string; finished_at: string | null } | null;
    };
    geofence_radius: {
        min: number;
        max: number;
        suggested_min: number;
        suggested_max: number;
    };
    can: { manage: boolean; integrations: boolean };
};

/** Slug de ruta de OrganizationStructureController. */
export type StructureEntity =
    | 'razones-sociales'
    | 'centros-trabajo'
    | 'sucursales'
    | 'departamentos'
    | 'puestos'
    | 'geocercas';

export type FieldDef = {
    key: string;
    label: string;
    type:
        | 'text'
        | 'textarea'
        | 'select'
        | 'combobox'
        | 'number'
        | 'switch'
        | 'time';
    options?: SelectOption[];
    placeholder?: string;
    required?: boolean;
    hint?: string;
    /** Ancho en la rejilla de 2 columnas. */
    full?: boolean;
    uppercase?: boolean;
    min?: number;
    max?: number;
    step?: number;
    unit?: string;
};

export type FormValue = string | number | boolean | null;
