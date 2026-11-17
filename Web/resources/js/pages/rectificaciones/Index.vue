<script setup lang="ts">
import {
    FilePen,
    FileSearch,
    Fingerprint,
    Gavel,
    Paperclip,
    Plus,
    ShieldCheck,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import RectificationController from '@/actions/App/Http/Controllers/Web/Operacion/RectificationController';
import {
    ComboboxField,
    DataTable,
    DateRangePicker,
    DateTimeDisplay,
    FilterBar,
    PageHeader,
    SearchInput,
    SelectField,
    StatusBadge,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/format';
import { currentQuery, toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, HelpStep } from '@/types';
import type {
    RectificationIndexProps,
    RectificationRow,
} from '@/types/operacion';
import EmployeeCell from './components/EmployeeCell.vue';
import InboxTabs from './components/InboxTabs.vue';
import RectificationFormSheet from './components/RectificationFormSheet.vue';
import { approvalTone, sortFromQuery, todayIn } from './components/shared';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Rectificaciones', href: '/rectificaciones' },
        ],
    },
});

const props = defineProps<RectificationIndexProps>();

const { filters, activeCount, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        branch_id: props.filters.branch_id,
        department_id: props.filters.department_id,
        employee_id: props.filters.employee_id,
        status: props.filters.status,
        from: props.filters.from,
        to: props.filters.to,
    },
    { keepQuery: ['tab', 'sort', 'direction', 'per_page'] },
);

const columns: DataTableColumn<RectificationRow>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'date', label: 'Fecha', sortable: true, class: 'w-32' },
    { key: 'kind', label: 'Tipo', hideBelow: 'md' },
    { key: 'proposal', label: 'Propuesta', hideBelow: 'lg' },
    { key: 'status', label: 'Estado' },
    {
        key: 'attachments',
        label: '',
        align: 'center',
        class: 'w-12',
        hideBelow: 'sm',
    },
    {
        key: 'created_at',
        label: 'Solicitada',
        sortable: true,
        hideBelow: 'xl',
        class: 'w-40',
    },
];

const sheetOpen = ref(false);
const prefill = ref<{ employee: string | null; date: string | null }>({
    employee: null,
    date: null,
});
const today = computed(() => todayIn(props.timezone));

onMounted(() => {
    const q = currentQuery();
    if (q.nuevo === '1' && props.can.create) {
        prefill.value = {
            employee: typeof q.employee_id === 'string' ? q.employee_id : null,
            date: typeof q.date === 'string' ? q.date : null,
        };
        sheetOpen.value = true;
    }
});

const help: HelpStep[] = [
    {
        title: 'Revisa la solicitud',
        text: 'Compara el marcaje original, la propuesta y la evidencia.',
        icon: FileSearch,
    },
    {
        title: 'Decide por nivel',
        text: 'Cada nivel del flujo aprueba o rechaza; rechazar exige motivo.',
        icon: Gavel,
    },
    {
        title: 'El original no cambia',
        text: 'La aprobación crea un ajuste encadenado y recalcula la jornada.',
        icon: Fingerprint,
    },
    {
        title: 'Todo queda auditado',
        text: 'Decisiones y evidencias se conservan con su huella SHA-256.',
        icon: ShieldCheck,
    },
];
</script>

<template>
    <PageHeader
        title="Rectificaciones"
        description="Correcciones de marcajes con trazabilidad completa."
        :icon="FilePen"
        :help="help"
    >
        <template #actions>
            <Button v-if="can.create" @click="sheetOpen = true">
                <Plus aria-hidden="true" />
                Nueva solicitud
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <InboxTabs :tab="tab" :counts="counts" />

        <DataTable
            :rows="requests.data"
            :columns="columns"
            :pagination="toPaginationMeta(requests)"
            :sort="sortFromQuery()"
            :row-href="(row) => RectificationController.show.url(row.id)"
            caption="Solicitudes de rectificación"
            :empty-icon="FilePen"
            :empty-title="
                tab === 'pendientes' ? 'Bandeja al día' : 'Sin solicitudes'
            "
            :empty-description="
                tab === 'pendientes'
                    ? 'No hay rectificaciones esperando decisión.'
                    : 'Ajusta los filtros para ver otros resultados.'
            "
        >
            <template #toolbar>
                <FilterBar :active-count="activeCount" @reset="reset">
                    <template #search>
                        <SearchInput
                            v-model="filters.search"
                            placeholder="Colaborador o número…"
                        />
                    </template>
                    <SelectField
                        v-model="filters.branch_id"
                        :options="options.branches"
                        prefix="Sucursal:"
                        placeholder="Todas"
                        clearable
                        size="sm"
                        class="w-52"
                    />
                    <SelectField
                        v-model="filters.department_id"
                        :options="options.departments"
                        prefix="Depto.:"
                        placeholder="Todos"
                        clearable
                        size="sm"
                        class="w-48"
                    />
                    <ComboboxField
                        v-model="filters.employee_id"
                        :options="options.employees"
                        placeholder="Colaborador"
                        clearable
                        class="w-56"
                    />
                    <SelectField
                        v-if="tab !== 'pendientes'"
                        v-model="filters.status"
                        :options="options.statuses"
                        prefix="Estado:"
                        placeholder="Todos"
                        clearable
                        size="sm"
                        class="w-44"
                    />
                    <DateRangePicker
                        v-model:start="filters.from"
                        v-model:end="filters.to"
                        placeholder="Periodo"
                        class="w-60"
                    />
                </FilterBar>
            </template>

            <template #cell-employee="{ row }">
                <EmployeeCell :employee="row.employee" />
            </template>
            <template #cell-date="{ row }">
                <span class="numeric text-sm font-medium">{{
                    formatDate(row.date)
                }}</span>
            </template>
            <template #cell-kind="{ row }">
                <span class="text-sm">{{ row.kind_label }}</span>
            </template>
            <template #cell-proposal="{ row }">
                <span
                    v-if="row.proposed_type_label || row.proposed_time"
                    class="flex items-center gap-1.5 text-sm"
                >
                    {{ row.proposed_type_label }}
                    <DateTimeDisplay
                        v-if="row.proposed_time"
                        :value="row.proposed_time"
                        :timezone="timezone"
                        format="time"
                        class="numeric font-semibold"
                    />
                </span>
                <span
                    v-else
                    class="line-clamp-1 max-w-56 text-xs text-muted-foreground"
                    >{{ row.reason_excerpt }}</span
                >
            </template>
            <template #cell-status="{ row }">
                <div class="grid gap-1">
                    <StatusBadge
                        :status="row.status"
                        :label="row.status_label"
                        :tone="approvalTone(row.status)"
                        size="sm"
                    />
                    <span
                        v-if="row.current_level_label"
                        class="text-[0.6875rem] text-muted-foreground"
                        >{{ row.current_level_label }}</span
                    >
                </div>
            </template>
            <template #cell-attachments="{ row }">
                <span
                    v-if="row.attachments_count > 0"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${row.attachments_count} evidencias`"
                >
                    <Paperclip class="size-3.5" aria-hidden="true" />{{
                        row.attachments_count
                    }}
                </span>
            </template>
            <template #cell-created_at="{ row }">
                <div class="grid text-xs">
                    <DateTimeDisplay
                        :value="row.created_at"
                        :timezone="timezone"
                        format="datetime"
                    />
                    <span class="text-muted-foreground">{{
                        row.requested_by
                    }}</span>
                </div>
            </template>
        </DataTable>
    </div>

    <RectificationFormSheet
        v-if="can.create"
        v-model:open="sheetOpen"
        :employees="options.employees"
        :kinds="options.kinds"
        :punch-types="options.punch_types"
        :today="today"
        :initial-employee-id="prefill.employee"
        :initial-date="prefill.date"
    />
</template>
