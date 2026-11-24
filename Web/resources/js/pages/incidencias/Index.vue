<script setup lang="ts">
import {
    ClipboardList,
    FileCheck2,
    Paperclip,
    Plus,
    RefreshCcw,
    Workflow,
} from '@lucide/vue';
import { ref } from 'vue';
import IncidenceController from '@/actions/App/Http/Controllers/Web/Operacion/IncidenceController';
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
import { incidenceLegendKey, legendDotClass } from '@/lib/attendance-status';
import { formatDate } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, HelpStep } from '@/types';
import type { IncidenceIndexProps, IncidenceRow } from '@/types/operacion';
import EmployeeCell from '../rectificaciones/components/EmployeeCell.vue';
import InboxTabs from '../rectificaciones/components/InboxTabs.vue';
import {
    approvalTone,
    sortFromQuery,
} from '../rectificaciones/components/shared';
import IncidenceFormSheet from './components/IncidenceFormSheet.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Incidencias', href: '/incidencias' },
        ],
    },
});

const props = defineProps<IncidenceIndexProps>();

const { filters, activeCount, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        branch_id: props.filters.branch_id,
        department_id: props.filters.department_id,
        employee_id: props.filters.employee_id,
        type_id: props.filters.type_id,
        status: props.filters.status,
        from: props.filters.from,
        to: props.filters.to,
    },
    { keepQuery: ['tab', 'sort', 'direction', 'per_page'] },
);

const columns: DataTableColumn<IncidenceRow>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'type', label: 'Tipo' },
    { key: 'starts_on', label: 'Periodo', sortable: true },
    { key: 'status', label: 'Estado' },
    { key: 'source', label: 'Origen', hideBelow: 'lg' },
    {
        key: 'attachments',
        label: '',
        align: 'center',
        class: 'w-12',
        hideBelow: 'sm',
    },
    {
        key: 'created_at',
        label: 'Registrada',
        sortable: true,
        hideBelow: 'xl',
        class: 'w-40',
    },
];

/** Misma leyenda que Asistencias, Calendario y Reportes (lib/attendance-status). */
const categoryDot = (category: string | null | undefined) =>
    legendDotClass(incidenceLegendKey(category));

const sheetOpen = ref(false);

const help: HelpStep[] = [
    {
        title: 'Catálogo cerrado',
        text: 'Sólo RH lo ajusta en Configuración › Incidencias. La falta injustificada la determina el sistema.',
        icon: ClipboardList,
    },
    {
        title: 'Evidencia por tipo',
        text: 'Incapacidad: certificado IMSS y folio. Permiso: formato autorizado. Luto: acta. Falta justificada: constancia.',
        icon: Paperclip,
    },
    {
        title: 'Jefe inmediato y RH',
        text: 'Cada tipo define quién autoriza. La omisión de checada abre una rectificación que firma el colaborador.',
        icon: Workflow,
    },
    {
        title: 'Mr. Lana People',
        text: 'Las incidencias aprobadas en People llegan solas; no se capturan dos veces.',
        icon: RefreshCcw,
    },
    {
        title: 'Recálculo',
        text: 'Al aprobarse, las jornadas afectadas se recalculan.',
        icon: RefreshCcw,
    },
    {
        title: 'Sin tocar marcajes',
        text: 'Las incidencias nunca modifican el registro original.',
        icon: FileCheck2,
    },
];
</script>

<template>
    <PageHeader
        title="Incidencias"
        description="Permisos, faltas e incapacidades."
        :icon="ClipboardList"
        :help="help"
    >
        <template #actions>
            <Button v-if="can.create" @click="sheetOpen = true">
                <Plus aria-hidden="true" />
                Nueva incidencia
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <InboxTabs :tab="tab" :counts="counts" />

        <DataTable
            :rows="incidences.data"
            :columns="columns"
            :pagination="toPaginationMeta(incidences)"
            :sort="sortFromQuery()"
            :row-href="(row) => IncidenceController.show.url(row.id)"
            caption="Incidencias"
            :empty-icon="ClipboardList"
            :empty-title="
                tab === 'pendientes' ? 'Nada por aprobar' : 'Sin incidencias'
            "
            :empty-description="
                tab === 'pendientes'
                    ? 'No hay incidencias pendientes de decisión.'
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
                    <ComboboxField
                        v-model="filters.type_id"
                        :options="options.types"
                        placeholder="Tipo"
                        clearable
                        class="w-56"
                    />
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
            <template #cell-type="{ row }">
                <span class="flex items-center gap-2 text-sm font-medium">
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :class="categoryDot(row.category)"
                        aria-hidden="true"
                    />
                    {{ row.type_name }}
                </span>
                <span class="pl-4 text-xs text-muted-foreground">{{
                    row.category_label
                }}</span>
            </template>
            <template #cell-starts_on="{ row }">
                <div class="grid text-sm">
                    <span class="numeric font-medium">
                        {{ formatDate(row.starts_on, 'date-short')
                        }}<template v-if="row.ends_on !== row.starts_on">
                            –
                            {{
                                formatDate(row.ends_on, 'date-short')
                            }}</template
                        >
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ row.days }} {{ row.days === 1 ? 'día' : 'días'
                        }}<template v-if="row.minutes">
                            · {{ row.minutes }} min</template
                        >
                    </span>
                </div>
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
            <template #cell-source="{ row }">
                <div class="grid text-xs text-muted-foreground">
                    <span>{{ row.source_label }}</span>
                    <span v-if="row.folio" class="numeric"
                        >Folio {{ row.folio }}</span
                    >
                    <span v-if="row.is_omission">Va a rectificación</span>
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

    <IncidenceFormSheet
        v-if="can.create"
        v-model:open="sheetOpen"
        :employees="options.employees"
        :types="options.types"
    />
</template>
