<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Landmark,
    Moon,
    Palmtree,
    Stethoscope,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    ComboboxField,
    DataTable,
    EmptyState,
    FilterBar,
    PageHeader,
    SearchInput,
    SelectField,
    UserAvatar,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
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
import { weekdayShort } from '@/pages/asistencias/components/tones';
import CalendarChip from '@/pages/calendario/components/CalendarChip.vue';
import type { ChipKind } from '@/pages/calendario/components/kinds';
import asistencias from '@/routes/asistencias';
import type { DataTableColumn } from '@/types';
import type {
    CalendarDayCell,
    CalendarEvent,
    CalendarProps,
    CalendarView,
    EmployeeCard,
} from '@/types/operacion';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Calendario', href: '/calendario' },
        ],
    },
});

const props = defineProps<CalendarProps>();

const { filters, activeCount, reset, processing } = useFilters(
    {
        view: props.filters.view as string,
        date: props.filters.date as string | null,
        search: props.filters.search ?? '',
        branch_id: props.filters.branch_id,
        department_id: props.filters.department_id,
        employee_id: props.filters.employee_id,
    },
    {
        defaults: {
            view: props.filters.view,
            date: props.filters.date,
            search: '',
        },
    },
);

function setView(value: unknown): void {
    if (value === 'month' || value === 'week' || value === 'day') {
        filters.view = value as CalendarView;
    }
}

const timeFormatter = computed(
    () =>
        new Intl.DateTimeFormat('es-MX', {
            timeZone: props.timezone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }),
);
const time = (iso: string) => timeFormatter.value.format(new Date(iso));

const title = computed(() => {
    const opts: Intl.DateTimeFormatOptions =
        props.view === 'month'
            ? { month: 'long', year: 'numeric', timeZone: 'UTC' }
            : props.view === 'day'
              ? {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric',
                    timeZone: 'UTC',
                }
              : { day: 'numeric', month: 'short', timeZone: 'UTC' };
    const fmt = new Intl.DateTimeFormat('es-MX', opts);
    const date = (d: string) => new Date(`${d}T12:00:00Z`);

    return props.view === 'week'
        ? `${fmt.format(date(props.range.start))} – ${fmt.format(date(props.range.end))}`
        : fmt.format(date(props.range.anchor));
});

const anchorMonth = computed(() => props.range.anchor.slice(0, 7));
const holidaysByDate = computed(() => {
    const map: Record<string, CalendarProps['holidays']> = {};
    props.holidays.forEach((h) => {
        (map[h.date] ??= []).push(h);
    });

    return map;
});
const eventsOn = (date: string): CalendarEvent[] =>
    props.events.filter((e) => e.starts_on <= date && e.ends_on >= date);

const eventKind = (e: CalendarEvent): ChipKind => e.kind;

const singleRoster = computed(() =>
    props.view === 'month' && props.roster?.data.length === 1
        ? props.roster.data[0]
        : null,
);
const singleDay = (date: string) =>
    singleRoster.value?.days.find((d) => d.date === date) ?? null;

type RosterRow = { employee: EmployeeCard | null; days: CalendarDayCell[] };

const rosterColumns = computed<DataTableColumn<RosterRow>[]>(() => [
    {
        key: 'employee',
        label: 'Colaborador',
        class: 'min-w-52 sticky left-0 z-10 bg-card',
    },
    ...props.range.days.map((d) => ({
        key: `d_${d}`,
        label: `${weekdayShort(d)} ${d.slice(8)}`,
        class: props.view === 'day' ? 'min-w-80' : 'w-36 min-w-36 max-w-40',
    })),
]);

function cellOf(row: RosterRow, key: string): CalendarDayCell | null {
    const date = key.slice(2);

    return row.days.find((d) => d.date === date) ?? null;
}

function incidenceKind(category: string | null): ChipKind {
    return category === 'disability'
        ? 'disability'
        : category === 'leave'
          ? 'leave'
          : 'incidence';
}

const legend: Array<{ kind: ChipKind; label: string }> = [
    { kind: 'shift', label: 'Turno' },
    { kind: 'rest', label: 'Descanso' },
    { kind: 'holiday', label: 'Festivo' },
    { kind: 'vacation', label: 'Vacaciones' },
    { kind: 'leave', label: 'Permiso' },
    { kind: 'disability', label: 'Incapacidad' },
    { kind: 'incidence', label: 'Incidencia' },
];

const help = [
    {
        title: 'Vistas',
        text: 'Mes muestra ausencias y festivos del equipo; semana y día, el turno de cada persona.',
        icon: CalendarRange,
    },
    {
        title: 'Turnos',
        text: 'Se resuelven con el horario asignado, excepciones y festivos vigentes.',
        icon: CalendarDays,
    },
    {
        title: 'Festivos',
        text: 'Pasa el cursor para ver el fundamento legal.',
        icon: Landmark,
    },
    {
        title: 'Línea punteada',
        text: 'Solicitud aún pendiente de aprobación.',
        icon: Palmtree,
    },
];

const isToday = (d: string) => d === props.range.today;
</script>

<template>
    <PageHeader
        title="Calendario"
        description="Turnos, descansos, festivos y ausencias."
        :icon="CalendarDays"
        :help="help"
    />

    <TooltipProvider :delay-duration="100">
        <div
            class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Anterior"
                    @click="filters.date = range.previous"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="filters.date = range.today"
                    >Hoy</Button
                >
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Siguiente"
                    @click="filters.date = range.next"
                >
                    <ChevronRight class="size-4" />
                </Button>
                <h2 class="ml-2 font-display text-xl font-bold capitalize">
                    {{ title }}
                </h2>
            </div>
            <ToggleGroup
                :model-value="filters.view"
                type="single"
                variant="outline"
                size="sm"
                aria-label="Vista"
                @update:model-value="setView"
            >
                <ToggleGroupItem value="month">Mes</ToggleGroupItem>
                <ToggleGroupItem value="week">Semana</ToggleGroupItem>
                <ToggleGroupItem value="day">Día</ToggleGroupItem>
            </ToggleGroup>
        </div>

        <FilterBar :active-count="activeCount" class="mb-4" @reset="reset">
            <template #search>
                <SearchInput
                    v-model="filters.search"
                    placeholder="Nombre o número…"
                />
            </template>
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
                class="w-56"
            />
        </FilterBar>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <CalendarChip v-for="l in legend" :key="l.kind" :kind="l.kind">{{
                l.label
            }}</CalendarChip>
        </div>

        <!-- ═════════ Mes ═════════ -->
        <section
            v-if="view === 'month'"
            :class="
                cn(
                    'overflow-hidden rounded-2xl border bg-card shadow-elev-1',
                    processing && 'opacity-70',
                )
            "
        >
            <div
                class="grid grid-cols-7 border-b bg-surface-2 text-center text-xs font-semibold text-muted-foreground"
            >
                <span
                    v-for="d in [
                        'Lun',
                        'Mar',
                        'Mié',
                        'Jue',
                        'Vie',
                        'Sáb',
                        'Dom',
                    ]"
                    :key="d"
                    class="py-2"
                    >{{ d }}</span
                >
            </div>
            <div class="grid grid-cols-7">
                <div
                    v-for="d in range.days"
                    :key="d"
                    :class="
                        cn(
                            'flex min-h-24 flex-col gap-1 border-r border-b p-1.5 sm:min-h-32 sm:p-2 [&:nth-child(7n)]:border-r-0',
                            !d.startsWith(anchorMonth) &&
                                'bg-surface-2/50 text-muted-foreground',
                        )
                    "
                >
                    <div class="flex items-center justify-between">
                        <span
                            :class="
                                cn(
                                    'flex size-6 items-center justify-center rounded-full numeric text-xs font-semibold',
                                    isToday(d) &&
                                        'bg-primary text-primary-foreground',
                                )
                            "
                            >{{ Number(d.slice(8)) }}</span
                        >
                        <span
                            v-if="eventsOn(d).length > 0"
                            class="numeric text-[0.65rem] text-muted-foreground"
                        >
                            {{ eventsOn(d).length }}
                        </span>
                    </div>
                    <Tooltip v-for="h in holidaysByDate[d] ?? []" :key="h.id">
                        <TooltipTrigger as-child>
                            <CalendarChip kind="holiday" class="cursor-help">
                                <Landmark
                                    class="size-3 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{ h.name }}</span>
                            </CalendarChip>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-64">
                            <p class="font-medium">
                                {{ h.name }} · {{ h.kind_label }}
                            </p>
                            <p>
                                {{
                                    h.legal_basis ?? 'Sin fundamento registrado'
                                }}
                            </p>
                            <p v-if="h.is_mandatory_rest">
                                Descanso obligatorio
                            </p>
                        </TooltipContent>
                    </Tooltip>
                    <template v-if="singleDay(d)">
                        <CalendarChip v-if="singleDay(d)?.shift" kind="shift">
                            {{ time(singleDay(d)!.shift!.starts_at) }}–{{
                                time(singleDay(d)!.shift!.ends_at)
                            }}
                        </CalendarChip>
                        <CalendarChip
                            v-else-if="singleDay(d)?.rest_day"
                            kind="rest"
                            >Descanso</CalendarChip
                        >
                    </template>
                    <Link
                        v-for="e in eventsOn(d).slice(0, 3)"
                        :key="e.id"
                        :href="e.href"
                        class="min-w-0"
                    >
                        <CalendarChip
                            :kind="eventKind(e)"
                            :pending="e.status === 'pending'"
                            class="hover:brightness-95"
                        >
                            <span class="truncate">{{ e.title }}</span>
                        </CalendarChip>
                    </Link>
                    <Tooltip v-if="eventsOn(d).length > 3">
                        <TooltipTrigger as-child>
                            <span
                                class="cursor-help px-1 text-[0.7rem] font-medium text-muted-foreground"
                                >+{{ eventsOn(d).length - 3 }} más</span
                            >
                        </TooltipTrigger>
                        <TooltipContent>
                            <p v-for="e in eventsOn(d).slice(3)" :key="e.id">
                                {{ e.title }} · {{ e.kind_label }}
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </div>
            </div>
            <div
                v-if="
                    events.length === 0 &&
                    holidays.length === 0 &&
                    !singleRoster
                "
                class="border-t p-6"
            >
                <EmptyState
                    :icon="Palmtree"
                    size="sm"
                    title="Sin ausencias ni festivos este mes"
                    description="Elige un colaborador para ver sus turnos."
                />
            </div>
        </section>

        <!-- ═════════ Semana / día ═════════ -->
        <template v-else>
            <DataTable
                v-if="roster"
                :rows="roster.data"
                :columns="rosterColumns"
                :pagination="toPaginationMeta(roster)"
                :row-key="(row: RosterRow) => row.employee?.id ?? 0"
                :loading="processing"
                empty-title="Sin colaboradores en el filtro"
                empty-description="Ajusta sucursal, departamento o búsqueda."
                :empty-icon="Users"
                caption="Turnos del equipo"
            >
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
                <template
                    v-for="d in range.days"
                    :key="d"
                    #[`cell-d_${d}`]="{ row }"
                >
                    <div
                        v-if="cellOf(row, `d_${d}`)"
                        :class="
                            cn(
                                'flex min-w-0 flex-col gap-1 rounded-lg p-0.5',
                                view !== 'day' && 'max-w-40',
                                isToday(d) && 'ring-1 ring-primary/30',
                            )
                        "
                    >
                        <template
                            v-for="cell in [cellOf(row, `d_${d}`)!]"
                            :key="cell.date"
                        >
                            <CalendarChip v-if="!cell.employed" kind="none"
                                >Sin relación laboral</CalendarChip
                            >
                            <template v-else>
                                <Tooltip v-if="cell.holiday">
                                    <TooltipTrigger as-child>
                                        <CalendarChip
                                            kind="holiday"
                                            class="cursor-help"
                                        >
                                            <Landmark
                                                class="size-3 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">{{
                                                cell.holiday.name
                                            }}</span>
                                        </CalendarChip>
                                    </TooltipTrigger>
                                    <TooltipContent>{{
                                        cell.holiday.legal_basis ??
                                        'Sin fundamento registrado'
                                    }}</TooltipContent>
                                </Tooltip>
                                <Tooltip v-if="cell.shift">
                                    <TooltipTrigger as-child>
                                        <CalendarChip
                                            kind="shift"
                                            class="cursor-help"
                                        >
                                            <Moon
                                                v-if="
                                                    cell.shift.kind === 'night'
                                                "
                                                class="size-3 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="numeric"
                                                >{{
                                                    time(cell.shift.starts_at)
                                                }}–{{
                                                    time(cell.shift.ends_at)
                                                }}</span
                                            >
                                        </CalendarChip>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p class="font-medium">
                                            {{ cell.shift.schedule_name }}
                                        </p>
                                        <p>
                                            {{ cell.shift.kind_label }} ·
                                            descanso
                                            {{
                                                formatMinutes(
                                                    cell.shift.break_minutes,
                                                )
                                            }}
                                        </p>
                                        <p v-if="cell.shift.from_exception">
                                            Excepción de horario
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                                <CalendarChip
                                    v-else-if="cell.rest_day"
                                    kind="rest"
                                    >Descanso</CalendarChip
                                >
                                <CalendarChip
                                    v-else-if="cell.no_schedule"
                                    kind="none"
                                    >Sin horario</CalendarChip
                                >
                                <CalendarChip
                                    v-if="cell.vacation"
                                    kind="vacation"
                                    :pending="
                                        cell.vacation.status === 'pending'
                                    "
                                >
                                    <Palmtree
                                        class="size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Vacaciones<span
                                        v-if="
                                            cell.vacation.status === 'pending'
                                        "
                                    >
                                        · pend.</span
                                    >
                                </CalendarChip>
                                <CalendarChip
                                    v-if="cell.incidence"
                                    :kind="
                                        incidenceKind(cell.incidence.category)
                                    "
                                    :pending="
                                        cell.incidence.status === 'pending'
                                    "
                                >
                                    <Stethoscope
                                        v-if="
                                            cell.incidence.category ===
                                            'disability'
                                        "
                                        class="size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{
                                        cell.incidence.name
                                    }}</span>
                                </CalendarChip>
                                <Link
                                    v-if="cell.status && row.employee"
                                    :href="
                                        asistencias.show.url({
                                            employee: row.employee.id,
                                            date: cell.date,
                                        })
                                    "
                                    class="px-1 text-[0.7rem] text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    {{ cell.status_label
                                    }}<template v-if="cell.worked_minutes">
                                        ·
                                        {{
                                            formatMinutes(
                                                cell.worked_minutes,
                                                'short',
                                            )
                                        }}</template
                                    >
                                </Link>
                            </template>
                        </template>
                    </div>
                </template>
            </DataTable>
        </template>

        <section
            v-if="
                view !== 'month' && (holidays.length > 0 || events.length > 0)
            "
            class="mt-6 grid gap-4 md:grid-cols-2"
        >
            <div
                v-if="holidays.length > 0"
                class="rounded-2xl border bg-card p-4 shadow-elev-1"
            >
                <p class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Landmark class="size-4" /> Festivos
                </p>
                <ul class="flex flex-col gap-2 text-sm">
                    <li
                        v-for="h in holidays"
                        :key="h.id"
                        class="flex items-start justify-between gap-3"
                    >
                        <span
                            ><span class="font-medium">{{ h.name }}</span>
                            <span class="text-muted-foreground"
                                >· {{ h.kind_label }}</span
                            ></span
                        >
                        <span
                            class="shrink-0 text-right text-xs text-muted-foreground"
                            ><span class="block font-medium text-foreground">{{
                                formatDate(h.date, 'date')
                            }}</span
                            ><span
                                v-if="h.legal_basis"
                                class="block whitespace-nowrap"
                                >{{ h.legal_basis }}</span
                            ></span
                        >
                    </li>
                </ul>
            </div>
            <div
                v-if="events.length > 0"
                class="rounded-2xl border bg-card p-4 shadow-elev-1"
            >
                <p class="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Palmtree class="size-4" /> Ausencias del periodo
                </p>
                <ul class="flex flex-col gap-2">
                    <li v-for="e in events.slice(0, 12)" :key="e.id">
                        <Link
                            :href="e.href"
                            class="flex items-center justify-between gap-3 rounded-lg px-2 py-1 text-sm hover:bg-surface-2"
                        >
                            <span class="min-w-0 truncate"
                                ><span class="font-medium">{{ e.title }}</span>
                                · {{ e.kind_label }}</span
                            >
                            <CalendarChip
                                :kind="eventKind(e)"
                                :pending="e.status === 'pending'"
                                >{{ e.status_label }}</CalendarChip
                            >
                        </Link>
                    </li>
                </ul>
            </div>
        </section>
    </TooltipProvider>
</template>
