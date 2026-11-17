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
