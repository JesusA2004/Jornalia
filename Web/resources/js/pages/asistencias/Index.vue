<script setup lang="ts">
import {
    AlarmClock,
    Bot,
    CalendarClock,
    Clock,
    Fingerprint,
    Hash,
    Hourglass,
    ListChecks,
    ShieldAlert,
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
        auto: props.filters.auto ? '1' : null,
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

/** Sólo jornadas / marcajes con salida automática por política (`?auto=1`). */
const onlyAuto = computed({
    get: () => filters.auto === '1',
    set: (v: boolean) => {
        filters.auto = v ? '1' : null;
    },
});

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
        label: 'Excedente',
        sortable: true,
        numeric: true,
        hideBelow: 'md',
    },
];

const punchColumns: DataTableColumn<PunchRow>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'server_time', label: 'Hora', sortable: true },
    { key: 'type', label: 'Tipo' },
    { key: 'source', label: 'Origen / autenticación', hideBelow: 'md' },
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
        text: 'Resultado calculado por día: horario, entrada, salida, trabajado, retardo y excedente.',
        icon: CalendarClock,
    },
    {
        title: 'Marcajes originales',
        text: 'Registro inmutable tal como se capturó. Nunca se edita ni se borra; las correcciones son rectificaciones.',
        icon: Fingerprint,
    },
    {
        title: 'Detalle',
        text: 'Abre una fila para ver el día completo: original, rectificación y horario reconocido.',
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
                <Fingerprint class="size-4" aria-hidden="true" />
                Marcajes originales
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
            :hint="`Excedente ${formatMinutes(totals.excess_minutes ?? 0)}`"
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
            label="Salidas automáticas"
            :value="totals.auto_clock_outs ?? 0"
            :hint="`Después del cierre ${formatMinutes(totals.after_cutoff_minutes ?? 0)} (no reconocido)`"
            :icon="Bot"
            tone="info"
        />
    </div>
    <div v-else class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
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
        <KpiTile
            label="Salidas automáticas"
            :value="totals.auto_clock_outs ?? 0"
            :icon="Bot"
            tone="neutral"
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
                    <Switch :id="`auto-${tab}`" v-model="onlyAuto" />
                    <Label :for="`auto-${tab}`" class="text-sm font-normal"
                        >Sólo salidas automáticas</Label
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
                <StatusBadge
                    :label="row.status_label"
                    :tone="workdayTone(row.status)"
                    size="sm"
                />
                <Badge
                    v-if="row.auto_clock_out_at"
                    variant="outline"
                    class="gap-1 border-dashed border-info/60 text-[0.65rem] text-info-soft-foreground"
                    :title="
                        row.after_cutoff_minutes
                            ? `${formatMinutes(row.after_cutoff_minutes)} después del cierre (no reconocidos)`
                            : undefined
                    "
                >
                    <Bot class="size-3" aria-hidden="true" /> Salida automática
                </Badge>
                <TooltipProvider
                    v-if="row.has_rectifications || row.anomalies.length > 0"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span
                                class="inline-flex size-5 items-center justify-center rounded-full bg-info-soft text-[0.65rem] font-bold text-info-soft-foreground"
                                >{{ row.has_rectifications ? 'R' : '!' }}</span
                            >
                        </TooltipTrigger>
                        <TooltipContent>
                            <p v-if="row.has_rectifications">
                                Con rectificación aprobada
                            </p>
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
        <template #cell-worked_minutes="{ row }">
            <MinutesDisplay
                :minutes="row.worked_minutes"
                format="short"
                class="font-medium"
            />
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
                <div class="flex h-9 items-center gap-2 rounded-md border px-3">
                    <Switch :id="`auto-${tab}`" v-model="onlyAuto" />
                    <Label :for="`auto-${tab}`" class="text-sm font-normal"
                        >Sólo salidas automáticas</Label
                    >
                </div>
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
                :value="row.is_system ? row.recognized_time : row.server_time"
                format="datetime"
                class="numeric text-sm font-medium"
            />
            <p v-if="row.is_system" class="text-xs text-muted-foreground">
                Registrada
                <DateTimeDisplay
                    :value="row.server_time"
                    format="time"
                    :tooltip="false"
                />
            </p>
        </template>
        <template #cell-type="{ row }">
            <span
                v-if="row.is_system"
                class="inline-flex items-center gap-1.5 rounded-md border border-dashed border-info/60 px-1.5 py-0.5 text-sm font-medium text-info-soft-foreground"
            >
                <Bot class="size-3.5" aria-hidden="true" />
                Salida automática
            </span>
            <span
                v-else
                class="inline-flex items-center gap-1.5 text-sm font-medium"
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
            <p class="text-sm">{{ row.source_label }}</p>
            <p class="text-xs text-muted-foreground">
                {{ row.auth_method_label }}
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

    <p class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
        <Hourglass class="size-3.5" aria-hidden="true" />
        Horas en {{ timezone }}.
    </p>
</template>
