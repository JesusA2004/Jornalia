<script setup lang="ts">
import {
    AlarmClock,
    CalendarClock,
    Clock,
    Download,
    Fingerprint,
    Hash,
    Hourglass,
    ListChecks,
    Lock,
    ShieldAlert,
    TriangleAlert,
    UserX,
    WifiOff,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import {
    ComboboxField,
    DataTable,
    DateRangePicker,
    DateTimeDisplay,
    FilterBar,
    KpiTile,
    MinutesDisplay,
    PageHeader,
    SearchInput,
    SelectField,
    StatusBadge,
    UserAvatar,
} from '@/components/app';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFilters } from '@/composables/useFilters';
import { formatMinutes, formatNumber } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import {
    punchStatusTone,
    workdayTone,
} from '@/pages/asistencias/components/tones';
import asistencias from '@/routes/asistencias';
import type { DataTableColumn } from '@/types';
import type {
    AttendanceIndexProps,
    PunchRow,
    Workday,
} from '@/types/operacion';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Asistencias', href: '/asistencias' },
        ],
    },
});

const props = defineProps<AttendanceIndexProps>();

const { filters, activeCount, reset, processing } = useFilters(
    {
        tab: props.tab as string,
        search: props.filters.search ?? '',
        branch_id: props.filters.branch_id,
        department_id: props.filters.department_id,
        employee_id: props.filters.employee_id,
        status: props.filters.status,
        from: props.filters.from,
        to: props.filters.to,
        omisiones: props.filters.omisiones ? '1' : null,
    },
    {
        defaults: {
            tab: props.tab as string,
            search: '',
            from: props.filters.from,
            to: props.filters.to,
        },
    },
);

watch(
    () => filters.tab,
    () => {
        filters.status = null;
    },
);

const statusOptions = computed(() =>
    filters.tab === 'marcajes'
        ? props.options.punch_statuses
        : props.options.workday_statuses,
);

/** «Sólo omisiones de checada» (`?omisiones=1`), combinable con los demás filtros. */
const onlyOmissions = computed({
    get: () => filters.omisiones === '1',
    set: (v: boolean) => {
        filters.omisiones = v ? '1' : null;
    },
});

/** Exportación CSV de marcajes originales con los filtros vigentes (sólo lectura). */
const exportHref = computed(() =>
    asistencias.export.url({
        query: {
            search: filters.search || undefined,
            branch_id: filters.branch_id ?? undefined,
            department_id: filters.department_id ?? undefined,
            employee_id: filters.employee_id ?? undefined,
            status: filters.status ?? undefined,
            from: filters.from ?? undefined,
            to: filters.to ?? undefined,
        },
    }),
);

const OMISSION_LABEL: Record<string, string> = {
    missing_clock_out: 'Omisión de salida',
    missing_clock_in: 'Omisión de entrada',
};

const sort = computed(() =>
    props.filters.sort
        ? {
              key: props.filters.sort,
              direction: (props.filters.direction === 'asc'
                  ? 'asc'
                  : 'desc') as 'asc' | 'desc',
          }
        : null,
);

const workdayColumns: DataTableColumn<Workday>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'date', label: 'Fecha', sortable: true },
    { key: 'status', label: 'Estado', sortable: true },
    { key: 'schedule', label: 'Horario', hideBelow: 'lg' },
    { key: 'first_in', label: 'Entrada / salida', sortable: true },
    {
        key: 'break_minutes',
        label: 'Descanso',
        numeric: true,
        hideBelow: 'xl',
    },
    {
        key: 'worked_minutes',
        label: 'Trabajado',
        sortable: true,
        numeric: true,
    },
    {
        key: 'late_minutes',
        label: 'Retardo',
        sortable: true,
        numeric: true,
        hideBelow: 'md',
    },
    {
        key: 'excess_minutes',
        label: 'Tiempo extra',
        sortable: true,
        numeric: true,
        hideBelow: 'md',
    },
    { key: 'origin', label: 'Origen del marcaje', hideBelow: 'lg' },
];

const punchColumns: DataTableColumn<PunchRow>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'server_time', label: 'Hora', sortable: true },
    { key: 'type', label: 'Tipo' },
    { key: 'source', label: 'Origen del marcaje', hideBelow: 'md' },
    { key: 'device', label: 'Dispositivo / recepción', hideBelow: 'xl' },
    { key: 'status', label: 'Estado' },
    { key: 'anomalies', label: 'Anomalías', hideBelow: 'lg' },
    {
        key: 'sequence',
        label: 'Secuencia',
        sortable: true,
        numeric: true,
        hideBelow: 'xl',
    },
];

const showHref = (row: {
    employee_id: number;
    date?: string;
    local_date?: string;
}) =>
    asistencias.show.url({
        employee: row.employee_id,
        date: (row.date ?? row.local_date) as string,
    });

const help = [
    {
        title: 'Jornadas',
        text: 'Por día: programado, registrado y calculado (trabajado, retardo, tiempo extra).',
        icon: CalendarClock,
    },
    {
        title: 'Marcajes originales',
        text: 'Sólo consulta y exportación para todos los roles. Guarda hora del dispositivo y de recepción.',
        icon: Fingerprint,
    },
    {
        title: 'Omisiones de checada',
        text: 'Falta la entrada o la salida. El sistema no la completa: se corrige con una rectificación firmada.',
        icon: TriangleAlert,
    },
    {
        title: 'Rectificada*',
        text: 'Día corregido con firma del colaborador. El asterisco abre la solicitud firmada.',
        icon: ListChecks,
    },
];
</script>

<template>
    <PageHeader
        title="Asistencias"
        description="Jornadas calculadas y registro original de marcajes."
        :icon="Clock"
        :help="help"
    />

    <Tabs
        :model-value="filters.tab"
        class="mb-5"
        @update:model-value="(v) => (filters.tab = String(v))"
    >
        <TabsList>
            <TabsTrigger value="jornadas">
                <CalendarClock class="size-4" aria-hidden="true" />
                Jornadas
            </TabsTrigger>
            <TabsTrigger value="marcajes">
                <Lock class="size-4" aria-hidden="true" />
                Marcajes originales · sólo lectura
            </TabsTrigger>
        </TabsList>
    </Tabs>

    <div
        v-if="tab === 'jornadas'"
        class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5"
    >
        <KpiTile
            label="Jornadas"
            :value="totals.days ?? 0"
            :icon="CalendarClock"
        />
        <KpiTile
            label="Trabajado"
            :value="formatMinutes(totals.worked_minutes ?? 0, 'decimal')"
            :hint="`Tiempo extra ${formatMinutes(totals.excess_minutes ?? 0)}`"
            :icon="Clock"
            tone="success"
        />
        <KpiTile
            label="Retardos"
            :value="totals.late_days ?? 0"
            :unit="(totals.late_days ?? 0) === 1 ? 'día' : 'días'"
            :hint="formatMinutes(totals.late_minutes ?? 0)"
            :icon="AlarmClock"
            tone="warning"
        />
        <KpiTile
            label="Faltas"
            :value="totals.absent_days ?? 0"
            :unit="(totals.absent_days ?? 0) === 1 ? 'jornada' : 'jornadas'"
            :icon="UserX"
            tone="danger"
        />
        <KpiTile
            label="Omisiones de checada"
            :value="totals.omissions ?? 0"
            hint="Sin entrada o sin salida; requieren rectificación firmada"
            :icon="TriangleAlert"
            tone="danger"
        />
    </div>
    <div v-else class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3">
        <KpiTile
            label="Marcajes"
            :value="totals.punches ?? 0"
            :icon="Fingerprint"
        />
        <KpiTile
            label="En revisión"
            :value="totals.flagged ?? 0"
            :icon="ShieldAlert"
            tone="warning"
        />
        <KpiTile
            label="Sin conexión"
            :value="totals.offline ?? 0"
            :icon="WifiOff"
            tone="info"
        />
    </div>

    <DataTable
        v-if="tab === 'jornadas'"
        :rows="workdays?.data ?? []"
        :columns="workdayColumns"
        :pagination="toPaginationMeta(workdays)"
        :sort="sort"
        :loading="processing"
        :row-href="(row: Workday) => showHref(row)"
        empty-title="Sin jornadas en el periodo"
        empty-description="Ajusta los filtros o el rango de fechas."
        :empty-icon="CalendarClock"
        caption="Jornadas por colaborador y día"
    >
        <template #toolbar>
            <FilterBar :active-count="activeCount" @reset="reset">
                <template #search>
                    <SearchInput
                        v-model="filters.search"
                        placeholder="Nombre o número…"
                    />
                </template>
                <DateRangePicker
                    v-model:start="filters.from"
                    v-model:end="filters.to"
                    class="w-64"
                    :clearable="false"
                />
                <SelectField
                    v-model="filters.branch_id"
                    :options="options.branches"
                    placeholder="Sucursal"
                    clearable
                    size="sm"
                    class="w-44"
                />
                <SelectField
                    v-model="filters.department_id"
                    :options="options.departments"
                    placeholder="Departamento"
                    clearable
                    size="sm"
                    class="w-44"
                />
                <ComboboxField
                    v-model="filters.employee_id"
                    :options="options.employees"
                    placeholder="Colaborador"
                    clearable
                    class="w-52"
                />
                <SelectField
                    v-model="filters.status"
                    :options="statusOptions"
                    prefix="Estado:"
                    placeholder="Todos"
                    clearable
                    size="sm"
                    class="w-44"
                />
                <div class="flex h-9 items-center gap-2 rounded-md border px-3">
                    <Switch id="solo-omisiones" v-model="onlyOmissions" />
                    <Label for="solo-omisiones" class="text-sm font-normal"
                        >Sólo omisiones de checada</Label
                    >
                </div>
            </FilterBar>
        </template>
        <template #cell-employee="{ row }">
            <UserAvatar
                :name="row.employee?.name ?? '—'"
                :subtitle="
                    [row.employee?.number, row.branch ?? row.employee?.branch]
                        .filter(Boolean)
                        .join(' · ')
                "
                show-name
                size="sm"
            />
        </template>
        <template #cell-date="{ row }">
            <DateTimeDisplay :value="row.date" format="date" :tooltip="false" />
        </template>
        <template #cell-status="{ row }">
            <div class="flex flex-wrap items-center gap-1.5">
                <a
                    v-if="row.has_rectifications && row.rectification?.url"
                    :href="row.rectification.url"
                    class="rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    title="Ver la rectificación firmada"
                    @click.stop
                >
                    <StatusBadge
                        label="Rectificada*"
                        :tone="workdayTone(row.status, { rectified: true })"
                        size="sm"
                    />
                </a>
                <StatusBadge
                    v-else-if="row.has_rectifications"
                    label="Rectificada*"
                    :tone="workdayTone(row.status, { rectified: true })"
                    size="sm"
                />
                <StatusBadge
                    v-else-if="row.omission"
                    :label="OMISSION_LABEL[row.omission] ?? row.status_label"
                    :tone="workdayTone(row.status, { omission: row.omission })"
                    size="sm"
                />
                <StatusBadge
                    v-else
                    :label="row.status_label"
                    :tone="workdayTone(row.status)"
                    size="sm"
                />
                <TooltipProvider v-if="row.anomalies.length > 0">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span
                                class="inline-flex size-5 items-center justify-center rounded-full bg-warning-soft text-[0.65rem] font-bold text-warning-soft-foreground"
                                >!</span
                            >
                        </TooltipTrigger>
                        <TooltipContent>
                            <p v-for="a in row.anomalies" :key="a.code">
                                {{ a.label }}
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </template>
        <template #cell-schedule="{ row }">
            <span
                v-if="row.scheduled_start"
                class="numeric text-sm whitespace-nowrap"
            >
                <DateTimeDisplay
                    :value="row.scheduled_start"
                    format="time"
                    :tooltip="false"
                />–<DateTimeDisplay
                    :value="row.scheduled_end"
                    format="time"
                    :tooltip="false"
                />
            </span>
            <span v-else class="text-sm text-muted-foreground">—</span>
            <p
                v-if="row.schedule_name"
                class="max-w-40 truncate text-xs text-muted-foreground"
                :title="row.schedule_name"
            >
                {{ row.schedule_name }}
            </p>
        </template>
        <template #cell-first_in="{ row }">
            <span class="numeric text-sm font-medium">
                <DateTimeDisplay
                    :value="row.first_in"
                    format="time"
                    :tooltip="false"
                />
                <span class="text-muted-foreground"> → </span>
                <DateTimeDisplay
                    :value="row.last_out"
                    format="time"
                    :tooltip="false"
                />
            </span>
        </template>
        <template #cell-break_minutes="{ row }">
            <MinutesDisplay
                :minutes="row.break_minutes || null"
                format="short"
                class="text-muted-foreground"
            />
        </template>
        <template #cell-worked_minutes="{ row }">
            <MinutesDisplay
                :minutes="row.worked_minutes"
                format="short"
                class="font-medium"
            />
            <p
                v-if="row.scheduled_minutes"
                class="text-xs whitespace-nowrap text-muted-foreground"
            >
                de {{ formatMinutes(row.scheduled_minutes, 'short') }}
            </p>
        </template>
        <template #cell-late_minutes="{ row }">
            <MinutesDisplay
                :minutes="row.late_minutes || null"
                format="short"
                :class="
                    row.late_minutes > 0
                        ? 'font-medium text-warning-soft-foreground'
                        : 'text-muted-foreground'
                "
            />
        </template>
        <template #cell-excess_minutes="{ row }">
            <MinutesDisplay
                :minutes="row.excess_minutes || null"
                format="short"
                :class="
                    row.excess_minutes > 0
                        ? 'font-medium text-info-soft-foreground'
                        : 'text-muted-foreground'
                "
            />
        </template>
        <template #cell-origin="{ row }">
            <span
                v-if="row.origin"
                :class="[
                    'text-xs',
                    row.origin.code === 'omission'
                        ? 'font-medium text-danger-soft-foreground'
                        : row.origin.code === 'rectified'
                          ? 'text-info-soft-foreground'
                          : 'text-muted-foreground',
                ]"
                >{{ row.origin.code === 'rectified' ? '*' : ''
                }}{{ row.origin.label }}</span
            >
            <span v-else class="text-sm text-muted-foreground">—</span>
        </template>
    </DataTable>

    <DataTable
        v-else
        :rows="punches?.data ?? []"
        :columns="punchColumns"
        :pagination="toPaginationMeta(punches)"
        :sort="sort"
        :loading="processing"
        :row-href="(row: PunchRow) => showHref(row)"
        empty-title="Sin marcajes en el periodo"
        empty-description="Ajusta los filtros o el rango de fechas."
        :empty-icon="Fingerprint"
        caption="Marcajes originales"
    >
        <template #toolbar>
            <FilterBar :active-count="activeCount" @reset="reset">
                <template #search>
                    <SearchInput
                        v-model="filters.search"
                        placeholder="Nombre o número…"
                    />
                </template>
                <DateRangePicker
                    v-model:start="filters.from"
                    v-model:end="filters.to"
                    class="w-64"
                    :clearable="false"
                />
                <SelectField
                    v-model="filters.branch_id"
                    :options="options.branches"
                    placeholder="Sucursal"
                    clearable
                    size="sm"
                    class="w-44"
                />
                <SelectField
                    v-model="filters.department_id"
                    :options="options.departments"
                    placeholder="Departamento"
                    clearable
                    size="sm"
                    class="w-44"
                />
                <ComboboxField
                    v-model="filters.employee_id"
                    :options="options.employees"
                    placeholder="Colaborador"
                    clearable
                    class="w-52"
                />
                <SelectField
                    v-model="filters.status"
                    :options="statusOptions"
                    prefix="Estado:"
                    placeholder="Todos"
                    clearable
                    size="sm"
                    class="w-44"
                />
                <Button variant="outline" size="sm" as-child>
                    <a :href="exportHref" download>
                        <Download class="size-4" aria-hidden="true" />
                        Exportar
                    </a>
                </Button>
            </FilterBar>
        </template>
        <template #cell-employee="{ row }">
            <UserAvatar
                :name="row.employee?.name ?? '—'"
                :subtitle="
                    [row.employee?.number, row.employee?.branch]
                        .filter(Boolean)
                        .join(' · ')
                "
                show-name
                size="sm"
            />
        </template>
        <template #cell-server_time="{ row }">
            <DateTimeDisplay
                :value="row.server_time"
                format="datetime"
                class="numeric text-sm font-medium"
            />
        </template>
        <template #cell-type="{ row }">
            <span
                :class="[
                    'inline-flex items-center gap-1.5 text-sm font-medium',
                    row.is_system && 'text-muted-foreground line-through',
                ]"
                :title="
                    row.is_system
                        ? 'Histórico generado por sistema: no se reconoce como marcaje'
                        : undefined
                "
            >
                <span
                    :class="[
                        'size-2 rounded-full',
                        row.type === 'clock_in'
                            ? 'bg-success'
                            : row.type === 'clock_out'
                              ? 'bg-danger'
                              : 'bg-warning',
                    ]"
                />
                {{ row.type_label }}
            </span>
        </template>
        <template #cell-source="{ row }">
            <p
                :class="[
                    'text-sm',
                    row.is_system && 'font-medium text-warning-soft-foreground',
                ]"
            >
                {{ row.origin.label }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ row.auth_method_label }}
            </p>
        </template>
        <template #cell-device="{ row }">
            <p class="numeric text-xs whitespace-nowrap">
                <span class="text-muted-foreground">Disp. </span>
                <DateTimeDisplay
                    :value="row.device_time"
                    format="time"
                    :tooltip="false"
                />
            </p>
            <p class="numeric text-xs whitespace-nowrap">
                <span class="text-muted-foreground">Recep. </span>
                <DateTimeDisplay
                    :value="row.received_at ?? row.server_time"
                    format="time"
                    :tooltip="false"
                />
            </p>
        </template>
        <template #cell-status="{ row }">
            <div class="flex flex-wrap items-center gap-1.5">
                <StatusBadge
                    :label="
                        row.status === 'flagged'
                            ? 'En revisión'
                            : row.status_label
                    "
                    :tone="punchStatusTone(row.status)"
                    size="sm"
                />
                <Badge
                    v-if="row.captured_offline"
                    variant="outline"
                    class="gap-1 text-[0.65rem]"
                >
                    <WifiOff class="size-3" aria-hidden="true" /> Offline
                </Badge>
            </div>
        </template>
        <template #cell-anomalies="{ row }">
            <div
                v-if="row.anomalies.length > 0"
                class="flex max-w-64 flex-wrap gap-1"
            >
                <span
                    v-for="a in row.anomalies"
                    :key="a.code"
                    class="rounded-md bg-warning-soft px-1.5 py-0.5 text-[0.7rem] text-warning-soft-foreground"
                    >{{ a.label }}</span
                >
            </div>
            <span v-else class="text-sm text-muted-foreground">—</span>
        </template>
        <template #cell-sequence="{ row }">
            <span
                class="inline-flex items-center gap-1 font-mono text-xs text-muted-foreground"
            >
                <Hash class="size-3" aria-hidden="true" />{{
                    formatNumber(row.sequence)
                }}
            </span>
        </template>
    </DataTable>

    <p
        class="mt-4 flex items-start gap-2 rounded-lg border border-brand/25 bg-brand-soft px-3 py-2 text-xs text-brand-soft-foreground"
    >
        <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        Los marcajes originales no se pueden editar ni borrar; toda corrección
        se hace en Rectificaciones con firma del colaborador
    </p>
    <p class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
        <Hourglass class="size-3.5" aria-hidden="true" />
        Horas en {{ timezone }}.
    </p>
</template>
