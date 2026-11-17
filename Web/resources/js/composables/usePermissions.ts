import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { hasPermission } from '@/lib/navigation';

export type UsePermissionsReturn = {
    permissions: ComputedRef<readonly string[] | undefined>;
    /** true si el usuario tiene el permiso (o si el backend no comparte permisos). */
    can: (permission: string) => boolean;
    canAny: (...permissions: string[]) => boolean;
    canAll: (...permissions: string[]) => boolean;
};

/**
 * Lectura de `page.props.auth.permissions` sólo para mostrar u ocultar UI.
 * La autorización real siempre ocurre en el backend (Policies).
 */
export function usePermissions(): UsePermissionsReturn {
    const page = usePage();
    const permissions = computed(() => page.props.auth?.permissions);

    const can = (permission: string) =>
        hasPermission(permissions.value, permission);

    return {
        permissions,
        can,
        canAny: (...list) => list.some(can),
        canAll: (...list) => list.every(can),
    };
}
