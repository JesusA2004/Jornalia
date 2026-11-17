<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpenCheck,
    Building2,
    CalendarHeart,
    FileSignature,
    GitBranch,
    MapPinned,
    Palmtree,
    TriangleAlert,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import ApprovalFlowController from '@/actions/App/Http/Controllers/Web/Admin/ApprovalFlowController';
import AttendancePolicyController from '@/actions/App/Http/Controllers/Web/Admin/AttendancePolicyController';
import CompanySettingsController from '@/actions/App/Http/Controllers/Web/Admin/CompanySettingsController';
import HolidayController from '@/actions/App/Http/Controllers/Web/Admin/HolidayController';
import IncidenceTypeController from '@/actions/App/Http/Controllers/Web/Admin/IncidenceTypeController';
import LegalVersionController from '@/actions/App/Http/Controllers/Web/Admin/LegalVersionController';
import PolicyDocumentController from '@/actions/App/Http/Controllers/Web/Admin/PolicyDocumentController';
import VacationPolicyController from '@/actions/App/Http/Controllers/Web/Admin/VacationPolicyController';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/utils';

type Tab = {
    label: string;
    href: string;
    icon: LucideIcon;
    permission: string;
};

const page = usePage();
const { can } = usePermissions();

const tabs = computed<Tab[]>(() =>
    [
        {
            label: 'Empresa',
            href: CompanySettingsController.edit.url(),
            icon: Building2,
            permission: 'settings.view',
        },
        {
            label: 'Asistencia',
            href: AttendancePolicyController.index.url(),
            icon: MapPinned,
            permission: 'settings.view',
        },
        {
            label: 'Autorizaciones',
            href: ApprovalFlowController.index.url(),
            icon: GitBranch,
            permission: 'settings.view',
        },
        {
            label: 'Incidencias',
            href: IncidenceTypeController.index.url(),
            icon: TriangleAlert,
            permission: 'settings.view',
        },
        {
            label: 'Vacaciones',
            href: VacationPolicyController.index.url(),
            icon: Palmtree,
            permission: 'settings.view',
        },
        {
            label: 'Festivos',
            href: HolidayController.index.url(),
            icon: CalendarHeart,
            permission: 'settings.view',
        },
        {
            label: 'Documentos',
            href: PolicyDocumentController.index.url(),
            icon: FileSignature,
            permission: 'settings.view',
        },
        {
            label: 'Normativa',
            href: LegalVersionController.index.url(),
            icon: BookOpenCheck,
            permission: 'settings.legal',
        },
    ].filter((tab) => can(tab.permission)),
);

const path = computed(() => page.url.split('?')[0]);

function isActive(href: string): boolean {
    const url = new URL(href, 'http://x').pathname;

    return url === '/configuracion'
        ? path.value === url
        : path.value.startsWith(url);
}
</script>

<template>
    <nav
        aria-label="Secciones de configuración"
        class="-mx-1 mb-6 flex [scrollbar-width:none] gap-1 overflow-x-auto rounded-2xl border bg-surface-2 p-1"
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
        </Link>
    </nav>
</template>
