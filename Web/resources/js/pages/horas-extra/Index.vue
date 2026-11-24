<script setup lang="ts">
import {
    AlertOctagon,
    BadgeCheck,
    Baby,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    Clock,
    Hourglass,
    MessageSquareText,
    Scale,
    ShieldAlert,
    Timer,
    TrendingUp,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    ComboboxField,
    DataTable,
    DatePicker,
    FilterBar,
    KpiTile,
    MinutesDisplay,
    PageHeader,
    SearchInput,
    SelectField,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFilters } from '@/composables/useFilters';
import { formatDate, formatMinutes } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { DataTableColumn, HelpStep } from '@/types';
import type {
    EmployeeCard,
    OvertimeDayCell,
    OvertimeIndexProps,
    OvertimeRecordCell,
    OvertimeWeekRow,
} from '@/types/operacion';
import EmployeeCell from '../rectificaciones/components/EmployeeCell.vue';
import { todayIn, visitWith } from '../rectificaciones/components/shared';
import OvertimeDecisionDialog from './components/OvertimeDecisionDialog.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Horas extra', href: '/horas-extra' },
        ],
    },
});

const props = defineProps<OvertimeIndexProps>();

const { filters, activeCount, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        branch_id: props.filters.branch_id,
        department_id: props.filters.department_id,
        employee_id: props.filters.employee_id,
        status: props.filters.status,
    },
    { keepQuery: ['week', 'all', 'per_page'] },
);

const today = computed(() => todayIn(props.timezone));

const weekPick = computed<string | null>({
    get: () => props.week.start,
    set: (value) => {
        if (value) {
            visitWith({ week: value });
        }
    },
});

function toggleAll(value: boolean): void {
    visitWith({ all: value ? '1' : null });
}

const weekLabel = computed(
    () =>
        `${formatDate(props.week.start, 'date-short')} – ${formatDate(props.week.end, 'date')}`,
);

const columns = computed<DataTableColumn<OvertimeWeekRow>[]>(() => [
    { key: 'employee', label: 'Colaborador', class: 'min-w-52' },
    ...props.week.days.map((d) => ({
        key: d,
        label: formatDate(d, 'weekday'),
        align: 'center' as const,
        class: 'min-w-24',
    })),
    {
        key: 'totals',
        label: 'Semana',
        align: 'right' as const,
        class: 'min-w-40',
    },
]);

const selected = ref<{
    record: OvertimeRecordCell;
    employee: EmployeeCard;
} | null>(null);
const dialogOpen = ref(false);

function openRecord(
    record: OvertimeRecordCell | null,
    employee: EmployeeCard,
): void {
    if (!record || !record.can_decide) {
        return;
    }
    selected.value = { record, employee };
    dialogOpen.value = true;
}

function cellTone(cell: OvertimeDayCell): string {
    if (cell.recorded_minutes === 0) {
        return '';
    }
    if (cell.over_limit_minutes > 0) {
        return 'bg-danger-soft text-danger-soft-foreground ring-1 ring-danger/40';
    }
    if (cell.pending_minutes > 0) {
        return 'bg-warning-soft text-warning-soft-foreground';
    }
    if (cell.authorized_minutes > 0) {
        return 'bg-success-soft text-success-soft-foreground';
    }

    return 'bg-neutral-soft text-neutral-soft-foreground';
}

const emptyHint = computed(() =>
    props.filters.all
        ? 'No hay jornadas registradas en esta semana.'
        : 'Nadie registró tiempo extra esta semana. Activa «Todo el personal» para ver a todos.',
);

const hours = (minutes: number | null | undefined): string =>
    minutes === null || minutes === undefined
        ? '—'
        : formatMinutes(minutes, 'short');

const dayOf = (row: OvertimeWeekRow, date: string) =>
    row.days.find((d) => d.date === date) ?? null;

const help: HelpStep[] = [
    {
        title: 'Tiempo extra registrado',
        text: 'Todo tiempo después del horario. Nada se descarta: queda pendiente.',
        icon: Clock,
    },
    {
        title: 'Autoriza o justifica',
        text: 'El jefe autoriza (pago) o justifica (se conserva sin pago extra).',
        icon: BadgeCheck,
    },
    {
        title: 'Dobles y triples',
        text: 'Por colaborador y semana, con los topes de la normativa del año (LFT arts. 66 y 68).',
        icon: Scale,
    },
    {
        title: 'Menores de 18',
        text: 'Tiempo extra prohibido: se alerta y sólo puede justificarse.',
        icon: Baby,
    },
];
</script>

<template>
    <PageHeader
        title="Horas extra"
        description="Tiempo extraordinario por semana y su autorización."
        :icon="Timer"
        :help="help"
    >
        <template #actions>
            <div class="flex items-center gap-1.5">
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Semana anterior"
                    @click="visitWith({ week: week.previous })"
                >
                    <ChevronLeft aria-hidden="true" />
                </Button>
                <DatePicker
                    v-model="weekPick"
                    :max="today"
                    class="w-56"
                    :placeholder="weekLabel"
                />
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Semana siguiente"
                    :disabled="week.next > today"
                    @click="visitWith({ week: week.next })"
                >
                    <ChevronRight aria-hidden="true" />
                </Button>
            </div>
        </template>
    </PageHeader>

    <p
        class="-mt-2 mb-4 flex items-center gap-2 text-sm font-medium text-muted-foreground"
    >
        <CalendarClock class="size-4" aria-hidden="true" /> Semana del
        {{ weekLabel }}
    </p>

    <div
        class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4 2xl:grid-cols-7"
    >
        <KpiTile
            label="Tiempo extra registrado"
            :value="hours(totals.recorded_minutes)"
            :icon="TrendingUp"
            tone="info"
            :hint="`${totals.overtime_days} días · ${totals.employees} personas`"
        />
        <KpiTile
            label="Autorizado"
            :value="hours(totals.authorized_minutes)"
            :icon="BadgeCheck"
            tone="success"
        />
        <KpiTile
            label="Justificado"
            :value="hours(totals.justified_minutes)"
            :icon="MessageSquareText"
            tone="neutral"
            hint="Se conserva sin pago extra"
        />
        <KpiTile
            label="Dobles"
            :value="hours(totals.double_minutes)"
            :icon="Clock"
            tone="primary"
            :hint="`Hasta ${hours(limits.weekly_max_minutes)}/semana`"
        />
        <KpiTile
            label="Triples"
            :value="hours(totals.triple_minutes)"
            :icon="Hourglass"
            tone="warning"
            :hint="`Excedente · máx. ${hours(limits.excess_weekly_max_minutes)}/semana`"
        />
        <KpiTile
            label="Sobre límite legal"
            :value="hours(totals.over_limit_minutes)"
            :icon="AlertOctagon"
            :tone="totals.over_limit_minutes > 0 ? 'danger' : 'neutral'"
            :class="
                totals.over_limit_minutes > 0 ? 'ring-2 ring-danger/40' : ''
            "
            hint="Alertado en Cumplimiento"
        />
        <KpiTile
            label="Pendientes"
            :value="totals.pending"
            :icon="ShieldAlert"
            tone="warning"
            :hint="`${hours(totals.pending_minutes)} por autorizar · todo tiempo registrado se revisa`"
        />
    </div>

    <div
        class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-xl border bg-surface-2 px-4 py-2.5 text-xs text-muted-foreground"
    >
        <span
            class="inline-flex items-center gap-1.5 font-semibold text-foreground"
            ><Scale class="size-3.5" aria-hidden="true" />
            {{ limits.name ?? limits.version }}</span
        >
        <span
            >Jornada máx.
            {{ hours(limits.weekly_ordinary_max_minutes) }}/sem</span
        >
        <span>Extra máx. {{ hours(limits.weekly_max_minutes) }}/sem</span>
        <span>Hasta {{ hours(limits.daily_max_minutes) }}/día</span>
        <span>Máx. {{ limits.max_days_per_week }} días/sem</span>
        <span
            >Excedente ≤ {{ hours(limits.excess_weekly_max_minutes) }}/sem (pago
            triple)</span
        >
        <span v-if="limits.daily_total_max_minutes"
            >Tope {{ hours(limits.daily_total_max_minutes) }}/día</span
        >
        <span
            v-if="!limits.minors_allowed"
            class="font-semibold text-danger-soft-foreground"
            >Menores de 18: prohibido</span
        >
        <span
            :class="
                cn(
                    'rounded-full px-2 py-0.5 font-semibold',
                    limits.verified
                        ? 'bg-success-soft text-success-soft-foreground'
                        : 'bg-warning-soft text-warning-soft-foreground',
                )
            "
            >{{ limits.verification_label
            }}<template v-if="limits.verified && limits.verified_at">
                · {{ formatDate(limits.verified_at, 'date-short') }}</template
            ></span
        >
    </div>

    <DataTable
        :rows="rows.data"
        :columns="columns"
        :row-key="(row) => row.employee.id"
        :pagination="toPaginationMeta(rows)"
        caption="Tiempo extraordinario por colaborador y día"
        :empty-icon="Timer"
        empty-title="Sin tiempo extraordinario"
        :empty-description="emptyHint"
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
                    v-model="filters.status"
                    :options="options.statuses"
                    prefix="Estado:"
                    placeholder="Todos"
                    clearable
                    size="sm"
                    class="w-44"
                />
                <template #actions>
                    <label
                        class="flex cursor-pointer items-center gap-2 text-sm"
                    >
                        <Switch
                            :model-value="props.filters.all"
                            @update:model-value="(v: boolean) => toggleAll(v)"
                        />
                        Todo el personal
                    </label>
                </template>
            </FilterBar>
        </template>

        <template #cell-employee="{ row }">
            <EmployeeCell :employee="row.employee" />
        </template>

        <template v-for="d in week.days" :key="d" #[`cell-${d}`]="{ row }">
            <template v-for="cell in [dayOf(row, d)]" :key="d">
                <TooltipProvider v-if="cell">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                :disabled="!cell.record?.can_decide"
                                :class="
                                    cn(
                                        'mx-auto flex w-full max-w-24 flex-col items-center rounded-lg px-1.5 py-1 text-center transition-all',
                                        cellTone(cell),
                                        cell.record?.can_decide
                                            ? 'cursor-pointer hover:scale-[1.03] hover:shadow-elev-1'
                                            : 'cursor-default',
                                    )
                                "
                                @click="openRecord(cell.record, row.employee)"
                            >
                                <MinutesDisplay
                                    :minutes="cell.ordinary_minutes"
                                    format="clock"
                                    fallback="—"
                                    class="numeric text-xs text-muted-foreground"
                                />
                                <span
                                    v-if="cell.recorded_minutes > 0"
                                    class="numeric text-sm font-bold"
                                >
                                    +{{ hours(cell.recorded_minutes) }}
                                </span>
                                <span
                                    v-if="cell.over_limit_minutes > 0"
                                    class="numeric text-[0.625rem] font-bold uppercase"
                                >
                                    {{ hours(cell.over_limit_minutes) }}
                                    límite
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="grid gap-0.5 text-xs">
                            <p class="font-semibold">
                                {{ formatDate(d, 'date-long') }}
                            </p>
                            <p>
                                Trabajado {{ hours(cell.worked_minutes) }} ·
                                ordinario {{ hours(cell.ordinary_minutes) }}
                            </p>
                            <template v-if="cell.recorded_minutes > 0">
                                <p>
                                    Tiempo extra
                                    {{ hours(cell.recorded_minutes) }} · dobles
                                    {{ hours(cell.double_minutes) }} · triples
                                    {{ hours(cell.triple_minutes) }}
                                </p>
                                <p>
                                    <template v-if="cell.pending_minutes"
                                        >Pendiente
                                        {{
                                            hours(cell.pending_minutes)
                                        }}</template
                                    ><template v-if="cell.authorized_minutes">
                                        · autorizado
                                        {{
                                            hours(cell.authorized_minutes)
                                        }}</template
                                    ><template v-if="cell.justified_minutes">
                                        · justificado
                                        {{
                                            hours(cell.justified_minutes)
                                        }}</template
                                    >
                                </p>
                                <p
                                    v-if="cell.minor"
                                    class="font-semibold text-danger-soft-foreground"
                                >
                                    Menor de 18: prohibido
                                </p>
                                <p
                                    v-if="cell.record?.justification"
                                    class="text-muted-foreground"
                                >
                                    {{ cell.record.justification }}
                                </p>
                                <p
                                    v-if="cell.record?.can_decide"
                                    class="font-medium"
                                >
                                    Clic para resolver
                                </p>
                            </template>
                            <p
                                v-else-if="cell.status_label"
                                class="text-muted-foreground"
                            >
                                {{ cell.status_label }}
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </template>
        </template>

        <template #cell-totals="{ row }">
            <div class="grid justify-items-end gap-0.5 text-xs">
                <span class="numeric text-sm font-bold"
                    >+{{ hours(row.totals.recorded_minutes) }}</span
                >
                <span class="text-muted-foreground">
                    Ord. {{ hours(row.totals.ordinary_minutes) }} · Aut.
                    {{ hours(row.totals.authorized_minutes) }}
                </span>
                <span class="text-muted-foreground">
                    Dobles {{ hours(row.totals.double_minutes) }} · Triples
                    {{ hours(row.totals.triple_minutes) }}
                </span>
                <span
                    v-if="row.totals.over_limit_minutes > 0"
                    class="rounded-full bg-danger-soft px-2 numeric font-semibold text-danger-soft-foreground"
                >
                    {{ hours(row.totals.over_limit_minutes) }}
                    sobre límite
                </span>
                <span
                    v-if="row.minor"
                    class="rounded-full bg-danger-soft px-2 font-semibold text-danger-soft-foreground"
                    >Menor de 18</span
                >
                <span
                    v-if="row.totals.pending > 0"
                    class="text-warning-soft-foreground"
                    >{{ row.totals.pending }} por autorizar</span
                >
            </div>
        </template>
    </DataTable>

    <p class="mt-3 text-xs text-muted-foreground">
        Todo tiempo registrado después del horario se muestra aquí para
        autorizar o justificar; el sistema no descarta tiempo por sí solo.
    </p>

    <OvertimeDecisionDialog
        v-model:open="dialogOpen"
        :record="selected?.record ?? null"
        :employee="selected?.employee ?? null"
    />
</template>
