<script setup lang="ts">
import { setLayoutProps } from '@inertiajs/vue3';
import {
    FileBarChart2,
    Filter,
    Fingerprint,
    Sigma,
    TableProperties,
    UserSearch,
} from '@lucide/vue';
import { computed } from 'vue';
import ReportController from '@/actions/App/Http/Controllers/Web/Operacion/ReportController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DataTable from '@/components/app/DataTable.vue';
import DateRangePicker from '@/components/app/DateRangePicker.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectField from '@/components/app/SelectField.vue';
import { useFilters } from '@/composables/useFilters';
import { usePermissions } from '@/composables/usePermissions';
import { legendSoftClass, workdayLegendKey } from '@/lib/attendance-status';
import { formatNumber } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn } from '@/types';
import type { ReportShowProps } from '@/types/operacion';
import ExportDialog from './components/ExportDialog.vue';
import ExportsList from './components/ExportsList.vue';
import ReportCell from './components/ReportCell.vue';

const props = defineProps<ReportShowProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Reportes', href: '/reportes' },
        ],
    },
});

setLayoutProps({
    breadcrumbs: [
        { title: 'Operación' },
        { title: 'Reportes', href: '/reportes' },
        { title: props.report.title },
    ],
});

type Row = Record<string, string | number | null>;

const { can } = usePermissions();
const canExport = computed(() => props.can.export && can('reports.export'));

const { filters, activeCount, reset, processing } = useFilters(
    {
        from: props.filters.from,
        to: props.filters.to,
        branch_id: props.filters.branch_id,
        department_id: props.filters.department_id,
        position_id: props.filters.position_id ?? null,
        region: props.filters.region ?? null,
        employee_id: props.filters.employee_id,
        status: props.filters.status,
        period_unit: props.filters.period_unit ?? null,
    },
    {
        only: [
            'rows',
            'totals',
            'capped',
            'needs_employee',
            'filters',
            'exports',
        ],
        defaults: { from: props.filters.from, to: props.filters.to },
    },
);

const NUMERIC = new Set(['number', 'minutes', 'percent']);

const columns = computed<DataTableColumn<Row>[]>(() =>
    props.report.columns.map((c, i) => ({
        key: c.key,
        label: c.label,
        numeric: NUMERIC.has(c.type),
        hideBelow: i > 5 && c.type === 'text' ? 'lg' : undefined,
        class: c.type === 'hash' ? 'w-44' : undefined,
    })),
);

/**
 * Insignia con la leyenda compartida de Asistencias (mismos colores en
 * Asistencias, Incidencias y Reportes). Sólo aplica a filas de jornada.
 */
const WORKDAY_STATUSES = new Set([
    'present',
    'late',
    'incomplete',
    'absent',
    'rest_day',
    'rest_day_worked',
    'holiday',
    'holiday_worked',
    'leave',
    'vacation',
    'unscheduled',
    'future',
]);

function legendClass(key: string, row: Row): string | null {
    const status = typeof row.status === 'string' ? row.status : null;
    if (key === 'status_label' && status && WORKDAY_STATUSES.has(status)) {
        return legendSoftClass(
            workdayLegendKey(status, {
                omission: (row.omission as string | null) || null,
            }),
        );
    }
    if (key === 'omission' && row.omission) {
        return legendSoftClass('omission');
    }

    return null;
}

const typeOf = (key: string) =>
    props.report.columns.find((c) => c.key === key)?.type ?? 'text';

const sumColumns = computed(() => props.report.columns.filter((c) => c.sum));
const hasTotals = computed(() => Object.keys(props.totals).length > 0);
const allows = (filter: string) =>
    (props.report.filters ?? ['branch', 'department', 'employee']).includes(
        filter,
    );

// Las filas de reporte no tienen id: clave estable por objeto.
const keys = new WeakMap<Row, number>();
let next = 0;
const rowKey = (row: Row): number => {
    let key = keys.get(row);
    if (key === undefined) {
        key = ++next;
        keys.set(row, key);
    }

    return key;
};

const help = [
    {
        title: 'Periodo obligatorio',
        text: 'La vista previa usa el mismo alcance que la exportación.',
        icon: Filter,
    },
    {
        title: 'Totales',
        text: 'Se calculan sobre todo el conjunto filtrado, no sólo la página.',
        icon: Sigma,
    },
    {
        title: 'Huella verificable',
        text: 'Cada archivo lleva folio, encabezado institucional y SHA-256.',
        icon: Fingerprint,
    },
    {
        title: 'Días completos',
        text: 'Días sin marcaje, descansos, vacaciones y festivos también aparecen.',
    },
];
</script>

<template>
    <PageHeader
        :title="report.title"
        :description="report.description"
        :eyebrow="report.category_label"
        :icon="FileBarChart2"
        :back-href="ReportController.index().url"
        back-label="Reportes"
        :help="help"
    >
        <template v-if="canExport" #actions>
            <ExportDialog
                :report="report.key"
                :title="report.title"
                :params="filters"
            />
        </template>
    </PageHeader>

    <div class="space-y-6">
        <FilterBar :active-count="activeCount" @reset="reset">
            <DateRangePicker
                v-model:start="filters.from"
                v-model:end="filters.to"
                :clearable="false"
                class="w-full sm:w-auto"
            />
            <SelectField
                v-if="options.branches.length > 1"
                v-model="filters.branch_id"
                :options="options.branches"
                placeholder="Sucursal"
                prefix="Sucursal:"
                clearable
                class="w-full sm:w-52"
            />
            <SelectField
                v-if="options.departments.length > 0"
                v-model="filters.department_id"
                :options="options.departments"
                placeholder="Departamento"
                prefix="Depto.:"
                clearable
                class="w-full sm:w-52"
            />
            <SelectField
                v-if="
                    allows('position') && (options.positions ?? []).length > 0
                "
                v-model="filters.position_id"
                :options="options.positions ?? []"
                placeholder="Puesto"
                prefix="Puesto:"
                clearable
                class="w-full sm:w-48"
            />
            <SelectField
                v-if="allows('region') && (options.regions ?? []).length > 0"
                v-model="filters.region"
                :options="options.regions ?? []"
                placeholder="Región"
                prefix="Región:"
                clearable
                class="w-full sm:w-44"
            />
            <SelectField
                v-if="allows('period_unit')"
                v-model="filters.period_unit"
                :options="options.period_units ?? []"
                placeholder="Semanal"
                prefix="Periodo:"
                clearable
                class="w-full sm:w-44"
            />
            <ComboboxField
                v-model="filters.employee_id"
                :options="options.employees"
                placeholder="Colaborador"
                search-placeholder="Nombre o número…"
                clearable
                class="w-full sm:w-60"
            />
            <SelectField
                v-if="options.statuses.length > 0"
                v-model="filters.status"
                :options="options.statuses"
                placeholder="Estado"
                prefix="Estado:"
                clearable
                class="w-full sm:w-44"
            />
        </FilterBar>

        <div
            v-if="sumColumns.length > 0"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6"
        >
            <div
                class="col-span-2 flex items-center gap-3 rounded-xl border bg-card px-4 py-3 shadow-elev-1 sm:col-span-1"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                >
                    <TableProperties class="size-4.5" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-xs text-muted-foreground">Registros</p>
                    <p
                        class="font-display numeric text-xl leading-tight font-bold"
                    >
                        {{ formatNumber(rows.total) }}
                    </p>
                </div>
            </div>
            <template v-if="hasTotals">
                <div
                    v-for="c in sumColumns"
                    :key="c.key"
                    class="rounded-xl border bg-card px-4 py-3 shadow-elev-1"
                >
                    <p
                        class="text-xs leading-snug text-muted-foreground"
                        :title="c.label"
                    >
                        {{ c.label }}
                    </p>
                    <p class="font-display text-xl leading-tight font-bold">
                        <ReportCell
                            :value="totals[c.key] ?? 0"
                            :type="c.type"
                            :timezone="timezone"
                        />
                    </p>
                </div>
            </template>
            <p
                v-else-if="capped || rows.total > 0"
                class="col-span-2 self-center text-xs text-muted-foreground sm:col-span-2"
            >
                Volumen alto: los totales se incluyen en el archivo exportado.
            </p>
        </div>

        <EmptyState
            v-if="needs_employee"
            :icon="UserSearch"
            bordered
            class="rounded-2xl"
            title="Elige un colaborador"
            description="Este reporte es individual."
        />

        <DataTable
            v-else
            :rows="rows.data"
            :columns="columns"
            :row-key="rowKey"
            :pagination="toPaginationMeta(rows)"
            :loading="processing"
            :only="['rows']"
            :per-page-options="[25, 50, 100]"
            :caption="`Vista previa: ${report.title}`"
            empty-title="Sin registros en el periodo"
            empty-description="Ajusta el periodo o los filtros. Los datos provienen de las jornadas y marcajes reales."
            :empty-icon="FileBarChart2"
        >
            <template
                v-for="c in report.columns"
                :key="c.key"
                #[`cell-${c.key}`]="{ row }"
            >
                <span
                    v-if="legendClass(c.key, row as Row)"
                    :class="[
                        'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                        legendClass(c.key, row as Row),
                    ]"
                    >{{ (row as Row)[c.key] }}</span
                >
                <ReportCell
                    v-else
                    :value="(row as Row)[c.key]"
                    :type="typeOf(c.key)"
                    :timezone="timezone"
                />
            </template>
        </DataTable>

        <ExportsList
            :exports="exports"
            :timezone="timezone"
            :show-title="false"
            title="Exportaciones de este reporte"
        />
    </div>
</template>
