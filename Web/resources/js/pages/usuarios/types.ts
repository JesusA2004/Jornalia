import type { LaravelPaginator, SelectOption } from '@/types';

/** Fila de `usuarios/Index` (UserController@index). */
export type OrgUserRow = {
    id: number;
    name: string;
    email: string;
    account_status: string;
    /** Pertenencia a la organización: active | inactive. */
    status: 'active' | 'inactive';
    access_all_branches: boolean;
    branch_ids: string[];
    branches: string[];
    roles: string[];
    last_login_at: string | null;
    two_factor: boolean;
    is_self: boolean;
    employee: { id: number; name: string; employee_number: string } | null;
    authority_expires_on: string | null;
    authority_revoked_at: string | null;
    /** Su rol exige doble factor (PermissionCatalog::TWO_FACTOR_REQUIRED). */
    two_factor_required: boolean;
};

export type EmployeeOption = SelectOption & { email: string | null };

export type UsersPageProps = {
    users: LaravelPaginator<OrgUserRow>;
    filters: {
        search?: string | null;
        status?: string | null;
        role?: string | null;
    };
    roles: SelectOption[];
    branches: SelectOption[];
    employees_without_account: EmployeeOption[];
    can: { manage: boolean; roles: boolean };
    flash_password: { email: string; password: string } | null;
    scoped_roles: string[];
    authority_max_days: number;
    open_review: { id: number; period: string; due_on: string } | null;
};

/** `usuarios/Revision` (UserAccessReviewController@index). */
export type ReviewFlag =
    | 'inactive'
    | 'terminated'
    | 'authority_expired'
    | 'segregation'
    | 'two_factor_missing'
    | 'scope_missing';

export type ReviewUserRow = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    branches: string[];
    access_all_branches: boolean;
    last_login_at: string | null;
    flags: ReviewFlag[];
};

export type ReviewDecision = {
    decision: 'keep' | 'deactivate';
    by: number;
    at: string;
    note: string | null;
};

export type ReviewPageProps = {
    review: {
        id: number;
        period: string;
        due_on: string;
        status: 'open' | 'completed';
        decisions: Record<string, ReviewDecision>;
        summary: Record<string, number> | null;
        completed_at: string | null;
        completed_by: string | null;
    } | null;
    history: Array<{
        id: number;
        period: string;
        status: string;
        completed_at: string | null;
    }>;
    users: ReviewUserRow[];
    inactive_after_days: number;
    can: { manage: boolean };
};

/** `usuarios/Roles` (RoleController@index). */
export type RoleRow = {
    id: number;
    name: string;
    permissions: string[];
    users_count: number;
    protected: boolean;
    is_default: boolean;
    deleted: boolean;
};

export type PermissionModule = {
    key: string;
    label: string;
    actions: Array<{ key: string; action: string; label: string }>;
};

export type RolesPageProps = {
    roles: RoleRow[];
    modules: PermissionModule[];
    can: { manage: boolean };
};

export const AUTHORITY_ROLE = 'Autoridad laboral';
