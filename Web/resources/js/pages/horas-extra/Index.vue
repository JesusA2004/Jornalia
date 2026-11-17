<script setup lang="ts">
import {
    AlertOctagon,
    BadgeCheck,
    Bot,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    Clock,
    Hourglass,
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

function cellTone(record: OvertimeRecordCell | null): string {
    if (!record || record.recorded_excess_minutes === 0) {
        return '';
    }
    if (record.over_limit_minutes > 0) {
        return 'bg-danger-soft text-danger-soft-foreground ring-1 ring-danger/40';
    }
    if (record.status === 'approved') {
        return 'bg-success-soft text-success-soft-foreground';
    }
    if (record.status === 'rejected') {
        return 'bg-neutral-soft text-neutral-soft-foreground';
    }

    return 'bg-warning-soft text-warning-soft-foreground';
}

const emptyHint = computed(() =>
    props.filters.all
        ? 'No hay jornadas registradas en esta semana.'
        : 'Nadie registró excedentes esta semana. Activa «Todo el personal» para ver a todos.',
);

const dayOf = (row: OvertimeWeekRow, date: string) =>
    row.days.find((d) => d.date === date) ?? null;

const help: HelpStep[] = [
    {
        title: 'Excedente registrado',
        text: 'Tiempo trabajado por encima del turno, calculado del registro.',
        icon: Clock,
    },
    {
        title: 'Dobles y triples',
        text: 'Clasificación legal vigente; lo que rebasa límites se marca en rojo.',
        icon: Scale,
    },
    {
        title: 'Autoriza con justificación',
        text: 'Nunca más de lo registrado; el excedente jamás se oculta.',
        icon: BadgeCheck,
    },
    {
        title: 'Después del cierre',
        text: 'Tiempo posterior a la salida automática por política: se conserva, pero no cuenta como jornada ni como extra.',
        icon: Bot,
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
        class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4 2xl:grid-cols-7"
    >
        <KpiTile
            label="Excedente registrado"
            :value="formatMinutes(totals.recorded_excess_minutes, 'short')"
            :icon="TrendingUp"
            tone="info"
            :hint="`${totals.records} días con excedente`"
        />
        <KpiTile
            label="Autorizado"
            :value="formatMinutes(totals.authorized_minutes, 'short')"
            :icon="BadgeCheck"
            tone="success"
        />
        <KpiTile
            label="Dobles"
            :value="formatMinutes(totals.double_minutes, 'short')"
            :icon="Clock"
            tone="primary"
        />
        <KpiTile
            label="Triples"
            :value="formatMinutes(totals.triple_minutes, 'short')"
            :icon="Hourglass"
            tone="warning"
        />
        <KpiTile
            label="Sobre límite"
            :value="formatMinutes(totals.over_limit_minutes, 'short')"
            :icon="AlertOctagon"
            :tone="totals.over_limit_minutes > 0 ? 'danger' : 'neutral'"
            :class="
                totals.over_limit_minutes > 0 ? 'ring-2 ring-danger/40' : ''
            "
            hint="Rebasa límites legales"
        />
        <KpiTile
            label="Pendientes"
            :value="totals.pending"
            :icon="ShieldAlert"
            tone="warning"
            hint="Por autorizar"
        />
        <KpiTile
            label="Después del cierre"
            :value="formatMinutes(totals.after_cutoff_minutes, 'short')"
            :icon="Bot"
            tone="neutral"
            hint="No reconocido · no es extra"
        />
    </div>

    <div
        class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border bg-surface-2 px-4 py-2.5 text-xs text-muted-foreground"
    >
        <span
            class="inline-flex items-center gap-1.5 font-semibold text-foreground"
            ><Scale class="size-3.5" aria-hidden="true" />
            {{ limits.version }}</span
        >
        <span
            >Máx. diario
            {{ formatMinutes(limits.daily_max_minutes, 'short') }}</span
        >
        <span
            >Máx. semanal
            {{ formatMinutes(limits.weekly_max_minutes, 'short') }}</span
        >
        <span>Hasta {{ limits.max_days_per_week }} días/semana</span>
        <span
            >Dobles ×{{ limits.double_multiplier }} · Excedentes ×{{
                limits.excess_multiplier
            }}</span
        >
        <span
            v-if="limits.verification_status !== 'verified'"
            class="font-medium text-warning-soft-foreground"
            >Normativa en verificación</span
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
                                        cellTone(cell.record),
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
                                    v-if="
                                        cell.record &&
                                        cell.record.recorded_excess_minutes > 0
                                    "
                                    class="numeric text-sm font-bold"
                                >
                                    +{{
                                        formatMinutes(
                                            cell.record.recorded_excess_minutes,
                                            'short',
                                        )
                                    }}
                                </span>
                                <span
                                    v-if="
                                        cell.record &&
                                        cell.record.over_limit_minutes > 0
                                    "
                                    class="numeric text-[0.625rem] font-bold uppercase"
                                >
                                    {{
                                        formatMinutes(
                                            cell.record.over_limit_minutes,
                                            'short',
                                        )
                                    }}
                                    límite
                                </span>
                                <span
                                    v-if="cell.after_cutoff_minutes > 0"
                                    class="mt-0.5 inline-flex items-center gap-0.5 rounded border border-dashed border-info/60 px-1 numeric text-[0.625rem] text-info-soft-foreground"
                                >
                                    <Bot
                                        class="size-2.5"
                                        aria-hidden="true"
                                    />{{
                                        formatMinutes(
                                            cell.after_cutoff_minutes,
                                            'short',
                                        )
                                    }}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="grid gap-0.5 text-xs">
                            <p class="font-semibold">
                                {{ formatDate(d, 'date-long') }}
                            </p>
                            <p>
                                Trabajado
                                {{
                                    formatMinutes(cell.worked_minutes, 'short')
                                }}
                                · ordinario
                                {{
                                    formatMinutes(
                                        cell.ordinary_minutes,
                                        'short',
                                    )
                                }}
                            </p>
                            <template v-if="cell.record">
                                <p>
                                    Excedente
                                    {{
                                        formatMinutes(
                                            cell.record.recorded_excess_minutes,
                                            'short',
                                        )
                                    }}
                                    · dobles
                                    {{
                                        formatMinutes(
                                            cell.record.double_minutes,
                                            'short',
                                        )
                                    }}
                                    · triples
                                    {{
                                        formatMinutes(
                                            cell.record.triple_minutes,
                                            'short',
                                        )
                                    }}
                                </p>
                                <p>
                                    {{ cell.record.status_label
                                    }}<template
                                        v-if="cell.record.authorized_minutes"
                                    >
                                        · autorizado
                                        {{
                                            formatMinutes(
                                                cell.record.authorized_minutes,
                                                'short',
                                            )
                                        }}</template
                                    >
                                </p>
                                <p
                                    v-if="cell.record.justification"
                                    class="text-muted-foreground"
                                >
                                    {{ cell.record.justification }}
                                </p>
                                <p
                                    v-if="cell.record.can_decide"
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
                            <p
                                v-if="cell.after_cutoff_minutes > 0"
                                class="text-info-soft-foreground"
                            >
                                Después del cierre (no reconocido):
                                {{
                                    formatMinutes(
                                        cell.after_cutoff_minutes,
                                        'short',
                                    )
                                }}
                                — no cuenta como tiempo extra
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </template>
        </template>

        <template #cell-totals="{ row }">
            <div class="grid justify-items-end gap-0.5 text-xs">
                <span class="numeric text-sm font-bold"
                    >+{{
                        formatMinutes(
                            row.totals.recorded_excess_minutes,
                            'short',
                        )
                    }}</span
                >
                <span class="text-muted-foreground">
                    Ord.
                    {{ formatMinutes(row.totals.ordinary_minutes, 'short') }} ·
                    Aut.
                    {{ formatMinutes(row.totals.authorized_minutes, 'short') }}
                </span>
                <span
                    v-if="row.totals.over_limit_minutes > 0"
                    class="rounded-full bg-danger-soft px-2 numeric font-semibold text-danger-soft-foreground"
                >
                    {{ formatMinutes(row.totals.over_limit_minutes, 'short') }}
                    sobre límite
                </span>
                <span
                    v-if="row.totals.after_cutoff_minutes > 0"
                    class="text-info-soft-foreground"
                    title="Después del cierre automático: no cuenta como tiempo extra"
                >
                    {{
                        formatMinutes(row.totals.after_cutoff_minutes, 'short')
                    }}
                    después del cierre (no reconocido)
                </span>
                <span
                    v-if="row.totals.pending > 0"
                    class="text-warning-soft-foreground"
                    >{{ row.totals.pending }} por autorizar</span
                >
            </div>
        </template>
    </DataTable>

    <OvertimeDecisionDialog
        v-model:open="dialogOpen"
        :record="selected?.record ?? null"
        :employee="selected?.employee ?? null"
    />
</template>
