import type { LaravelPaginator, SelectOption } from '@/types';

export type DeviceStatus = 'pending' | 'approved' | 'revoked';

export type DevicePendingReason = 'device_change' | 'new_device' | 'relink';

export type DeviceBrief = {
    id: number;
    name: string;
    model: string | null;
    last_seen_at: string | null;
};

export type DeviceRow = {
    id: number;
    name: string;
    platform: string;
    os_version: string | null;
    app_version: string | null;
    model: string | null;
    biometric_available: boolean;
    /** Señales reportadas por la app: rooted, jailbroken, emulator, mock_location. */
    integrity_flags: string[];
    integrity_reported_at: string | null;
    is_corporate: boolean;
    status: DeviceStatus;
    created_at: string | null;
    last_seen_at: string | null;
    approved_at: string | null;
    approved_by: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revoke_reason: string | null;
    /** Sólo en pendientes: por qué espera autorización. */
    pending_reason: DevicePendingReason | null;
    /** Pendiente por cambio de teléfono: teléfono vigente que se revocará al autorizar. */
    previous_device: DeviceBrief | null;
    /** Revocado por reemplazo: teléfono que lo sustituyó. */
    replaced_by: DeviceBrief | null;
    employee: { id: number; name: string; employee_number: string } | null;
    user: { name: string; email: string } | null;
};

export type DeviceFilters = {
    status?: DeviceStatus | null;
    search?: string | null;
    platform?: string | null;
    corporate?: 'yes' | 'no' | null;
};

export type DevicesPageProps = {
    devices: LaravelPaginator<DeviceRow>;
    filters: DeviceFilters;
    counts: Record<DeviceStatus, number>;
    can: { approve: boolean; revoke: boolean; kiosk: boolean };
    kiosks: KioskRow[];
    branches: SelectOption[];
    /** Código de activación recién generado (se muestra una sola vez). */
    kiosk_activation: KioskActivationFlash | null;
};

export type KioskStatus = 'pending' | 'active' | 'revoked';

export type KioskRow = {
    id: number;
    name: string;
    status: KioskStatus;
    status_label: string;
    branch: string | null;
    model: string | null;
    platform: string | null;
    os_version: string | null;
    app_version: string | null;
    activated_at: string | null;
    last_seen_at: string | null;
    activation_expires_at: string | null;
    revoked_at: string | null;
    revoke_reason: string | null;
};

export type KioskActivationFlash = {
    kiosk_id: number;
    name: string;
    code: string;
    expires_at: string | null;
};
