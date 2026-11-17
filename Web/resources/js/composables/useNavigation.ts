import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import {
    filterNavigation,
    findNavigationMatch,
    navigation,
} from '@/lib/navigation';
import type { BreadcrumbItem, NavGroup, NavItem } from '@/types';

export type UseNavigationReturn = {
    /** Grupos visibles según `auth.permissions`. */
    groups: ComputedRef<NavGroup[]>;
    /** Elemento de navegación activo (por prefijo de URL). */
    active: ComputedRef<{ group: NavGroup; item: NavItem } | null>;
    /** Migas derivadas de la navegación (si la página no define las suyas). */
    fallbackBreadcrumbs: ComputedRef<BreadcrumbItem[]>;
};

export function useNavigation(): UseNavigationReturn {
    const page = usePage();
    const { currentUrl } = useCurrentUrl();

    const groups = computed(() =>
        filterNavigation(navigation, page.props.auth?.permissions),
    );

    const active = computed(() => findNavigationMatch(currentUrl.value));

    const fallbackBreadcrumbs = computed<BreadcrumbItem[]>(() => {
        if (currentUrl.value.startsWith('/settings')) {
            return [{ title: 'Mi cuenta' }];
        }

        if (!active.value) {
            return [];
        }

        return [
            { title: active.value.group.title },
            { title: active.value.item.title, href: active.value.item.href },
        ];
    });

    return { groups, active, fallbackBreadcrumbs };
}
