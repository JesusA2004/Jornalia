<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BellOff,
    BellRing,
    CalendarClock,
    Megaphone,
    Plus,
    Send,
    Settings2,
    Undo2,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AnnouncementController from '@/actions/App/Http/Controllers/Web/Comunicados/AnnouncementController';
import DataTable from '@/components/app/DataTable.vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/composables/useFilters';
import { toPaginationMeta } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { DataTableColumn, HelpStep, Tone } from '@/types';
import AnnouncementStats from './components/AnnouncementStats.vue';
import ReminderSettingsDialog from './components/ReminderSettingsDialog.vue';
import type {
    AnnouncementRow,
    AnnouncementsIndexProps,
    AnnouncementStatus,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Comunicados', href: '/comunicados' }],
    },
});

const props = defineProps<AnnouncementsIndexProps>();

const help: HelpStep[] = [
    {
        title: 'Redacta',
        text: 'Título corto y mensaje. Prioridad alta para avisos urgentes.',
        icon: Megaphone,
    },
    {
        title: 'Destinatarios',
        text: 'Todos, sucursal, departamento, puesto o personas; sólo dentro de tu alcance.',
        icon: Users,
    },
    {
        title: 'Envío',
        text: 'Inmediato o programado. Llega a la bandeja de la app y como aviso push.',
        icon: Send,
    },
    {
        title: 'Pantalla de bloqueo',
        text: 'El push muestra un texto neutro; el detalle sólo dentro de la app.',
        icon: BellRing,
    },
    {
        title: 'Retirar',
        text: 'Deja de mostrarse en la app. Nunca se borra y puede reactivarse.',
        icon: Undo2,
    },
    {
        title: 'Avisos automáticos',
        text: 'Recordatorios de entrada y salida pendientes según el horario de cada colaborador.',
        icon: CalendarClock,
    },
    {
        title: 'Acuse de marcaje',
        text: 'Cada entrada y salida genera un acuse con fecha y hora en la bandeja de la app. Es obligatorio y no se desactiva.',
        icon: BellRing,
    },
    {
        title: 'Confirmación de lectura',
        text: 'En comunicados importantes el colaborador confirma que lo leyó; el detalle muestra quién falta.',
        icon: Users,
    },
];

const { filters, activeCount, reset } = useFilters(
    {
        status: props.filters.status ?? null,
        search: props.filters.search ?? '',
    },
    { only: ['announcements', 'filters'], defaults: { search: '' } },
);

const tabs: { value: AnnouncementStatus | null; label: string }[] = [
    { value: null, label: 'Todos' },
    { value: 'sent', label: 'Enviados' },
    { value: 'scheduled', label: 'Programados' },
    { value: 'withdrawn', label: 'Retirados' },
];

const STATUS: Record<AnnouncementStatus, { label: string; tone: Tone }> = {
    sent: { label: 'Enviado', tone: 'success' },
    scheduled: { label: 'Programado', tone: 'info' },
    withdrawn: { label: 'Retirado', tone: 'neutral' },
};

const columns: DataTableColumn<AnnouncementRow>[] = [
    { key: 'title', label: 'Comunicado', mobile: 'title' },
    { key: 'audience', label: 'Destinatarios', hideBelow: 'md' },
    { key: 'status', label: 'Estado', class: 'w-32' },
    { key: 'when', label: 'Fecha', hideBelow: 'lg' },
    { key: 'stats', label: 'Entrega', class: 'w-40' },
];

const settingsOpen = ref(false);
const whenOf = (row: AnnouncementRow) =>
    row.status === 'scheduled'
        ? row.scheduled_at
        : (row.sent_at ?? row.created_at);
const rowHref = (row: AnnouncementRow) =>
    AnnouncementController.show(row.id).url;
const reminderSummary = computed(() => {
    const on = [
        props.settings.late.enabled && 'entrada',
        props.settings.clock_out.enabled && 'salida',
    ].filter(Boolean);

    return on.length
        ? `Automáticos: ${on.join(' y ')}`
        : 'Automáticos apagados';
});
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            title="Comunicados"
            description="Mensajes a la app de los colaboradores."
            :icon="Megaphone"
            :help="help"
        >
            <template #actions>
                <Button
                    v-if="can.send"
                    variant="outline"
                    @click="settingsOpen = true"
                >
                    <Settings2 aria-hidden="true" />
                    <span class="hidden sm:inline">{{ reminderSummary }}</span>
                    <span class="sm:hidden">Automáticos</span>
                </Button>
                <Button v-if="can.send" as-child>
                    <Link :href="AnnouncementController.create()">
                        <Plus aria-hidden="true" />
                        Nuevo
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <Alert v-if="!push.enabled">
            <BellOff class="size-4" aria-hidden="true" />
            <AlertDescription
                >Push sin configurar: los avisos llegan sólo a la bandeja de la
                app.</AlertDescription
            >
        </Alert>

        <p
            v-if="punch_receipt.mandatory"
            class="inline-flex items-center gap-2 rounded-full bg-success-soft px-3 py-1 text-xs font-medium text-success-soft-foreground"
        >
            <BellRing class="size-3.5" aria-hidden="true" />
            Acuse de entrada y salida: obligatorio
        </p>

        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Estado">
            <button
                v-for="tab in tabs"
                :key="tab.label"
                type="button"
                role="tab"
                :aria-selected="filters.status === tab.value"
                :class="
                    cn(
                        'inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium transition-standard',
                        filters.status === tab.value
                            ? 'border-transparent bg-foreground text-background shadow-elev-1'
                            : 'bg-card text-muted-foreground hover:text-foreground',
                    )
                "
                @click="filters.status = tab.value"
            >
                {{ tab.label }}
            </button>
        </div>

        <FilterBar :active-count="activeCount" @reset="reset">
            <template #search>
                <SearchInput
                    v-model="filters.search"
                    placeholder="Buscar por título"
                />
            </template>
        </FilterBar>

        <DataTable
            :rows="announcements.data"
            :columns="columns"
            :pagination="toPaginationMeta(announcements)"
            :only="['announcements', 'filters']"
            :row-href="rowHref"
            :empty-icon="Megaphone"
            empty-title="Sin comunicados"
            :empty-description="
                can.send
                    ? 'Envía el primero con «Nuevo».'
                    : 'Aquí verás los comunicados enviados.'
            "
            caption="Comunicados"
        >
            <template #cell-title="{ row }">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 font-semibold">
                        <span class="truncate">{{ row.title }}</span>
                        <Badge
                            v-if="row.priority === 'high'"
                            variant="destructive"
                            class="shrink-0"
                            >Alta</Badge
                        >
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ row.excerpt }}
                    </p>
                </div>
            </template>

            <template #cell-audience="{ row }">
                <span class="text-sm">{{ row.audience_label }}</span>
                <span
                    v-if="row.audience_count"
                    class="text-xs text-muted-foreground"
                >
                    · {{ row.audience_count }}</span
                >
            </template>

            <template #cell-status="{ row }">
                <StatusBadge
                    :label="STATUS[row.status].label"
                    :tone="STATUS[row.status].tone"
                    size="sm"
                />
            </template>

            <template #cell-when="{ row }">
                <DateTimeDisplay :value="whenOf(row)" format="relative" />
            </template>

            <template #cell-stats="{ row }">
                <AnnouncementStats
                    v-if="row.status !== 'scheduled'"
                    :stats="row.stats"
                    compact
                />
                <span v-else class="text-xs text-muted-foreground">—</span>
            </template>
        </DataTable>

        <ReminderSettingsDialog
            v-if="can.send"
            v-model:open="settingsOpen"
            :settings="settings"
        />
    </div>
</template>
