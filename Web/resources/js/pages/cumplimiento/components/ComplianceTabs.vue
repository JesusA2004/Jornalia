<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Hash, History, TriangleAlert } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/utils';

/**
 * Pestañas del módulo Cumplimiento: Alertas legales, Integridad del registro
 * y Bitácora de cambios. Cada pestaña se muestra según su permiso.
 */
const props = defineProps<{ openAlerts?: number | null }>();

const page = usePage();
const { can } = usePermissions();

type Tab = {
    label: string;
    href: string;
    icon: LucideIcon;
    permission: string;
    count?: number | null;
};

const tabs = computed<Tab[]>(() =>
    [
        {
            label: 'Alertas legales',
            href: '/cumplimiento',
            icon: TriangleAlert,
            permission: 'compliance.view',
            count: props.openAlerts ?? null,
        },
        {
            label: 'Integridad del registro',
            href: '/cumplimiento/integridad',
            icon: Hash,
            permission: 'integrity.view',
        },
        {
            label: 'Bitácora de cambios',
            href: '/cumplimiento/bitacora',
            icon: History,
            permission: 'audit.view',
        },
    ].filter((tab) => can(tab.permission)),
);

const path = computed(() => page.url.split('?')[0]);

function isActive(href: string): boolean {
    return href === '/cumplimiento'
        ? path.value === href
        : path.value.startsWith(href);
}
</script>

<template>
    <nav
        aria-label="Secciones de cumplimiento"
        class="mb-6 flex [scrollbar-width:none] gap-1 overflow-x-auto rounded-2xl border bg-surface-2 p-1"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.href"
            :href="tab.href"
            preserve-scroll
            :aria-current="isActive(tab.href) ? 'page' : undefined"
            :class="
                cn(
                    'inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium text-muted-foreground transition-standard hover:bg-card hover:text-foreground',
                    isActive(tab.href) &&
                        'bg-card text-foreground shadow-elev-1 ring-1 ring-border',
                )
            "
        >
            <component :is="tab.icon" class="icon-sm" aria-hidden="true" />
            {{ tab.label }}
            <span
                v-if="tab.count"
                class="rounded-md bg-surface-2 px-1.5 text-xs tabular-nums"
                >{{ tab.count }}</span
            >
        </Link>
    </nav>
</template>
