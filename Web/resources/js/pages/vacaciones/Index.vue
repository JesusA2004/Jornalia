<script setup lang="ts">
import {
    CalendarDays,
    CalendarRange,
    Info,
    ListChecks,
    Palmtree,
    Plus,
    Scale,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import VacationController from '@/actions/App/Http/Controllers/Web/Operacion/VacationController';
import {
    ComboboxField,
    DataTable,
    DateRangePicker,
    DateTimeDisplay,
    FilterBar,
    KpiTile,
    PageHeader,
    SearchInput,
    SectionCard,
    SelectField,
    StatusBadge,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { DataTableColumn, HelpStep } from '@/types';
import type {
    EmployeeCard,
    VacationBalance,
    VacationIndexProps,
    VacationRow,
} from '@/types/operacion';
import EmployeeCell from '../rectificaciones/components/EmployeeCell.vue';
import {
    approvalTone,
    sortFromQuery,
    todayIn,
    visitWith,
} from '../rectificaciones/components/shared';
import AbsenceCalendar from './components/AbsenceCalendar.vue';
import BalanceMeter from './components/BalanceMeter.vue';
import VacationFormSheet from './components/VacationFormSheet.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Vacaciones', href: '/vacaciones' },
        ],
    },
});

const props = defineProps<VacationIndexProps>();

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
    { keepQuery: ['tab', 'month', 'sort', 'direction', 'per_page'] },
);

const today = computed(() => todayIn(props.timezone));
const sheetOpen = ref(false);
const canRequest = computed(
    () => props.can.create || props.own_employee_id !== null,
);

const tabs = [
    { key: 'solicitudes' as const, label: 'Solicitudes', icon: ListChecks },
    { key: 'saldos' as const, label: 'Saldos', icon: Wallet },
    { key: 'calendario' as const, label: 'Calendario', icon: CalendarRange },
];

const requestColumns: DataTableColumn<VacationRow>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'starts_on', label: 'Periodo', sortable: true },
    { key: 'days', label: 'Días', numeric: true, class: 'w-20' },
    { key: 'status', label: 'Estado' },
    {
        key: 'created_at',
        label: 'Solicitada',
        sortable: true,
        hideBelow: 'lg',
        class: 'w-40',
    },
];

type BalanceRow = {
    employee: EmployeeCard | null;
    balance: VacationBalance | null;
};
const balanceColumns: DataTableColumn<BalanceRow>[] = [
    { key: 'employee', label: 'Colaborador' },
    { key: 'year', label: 'Año de servicio', hideBelow: 'md' },
    { key: 'entitled', label: 'Derecho', numeric: true },
    { key: 'meter', label: 'Uso', class: 'min-w-48', hideBelow: 'sm' },
    { key: 'available', label: 'Disponibles', numeric: true },
    { key: 'premium', label: 'Prima', numeric: true, hideBelow: 'lg' },
];

const help: HelpStep[] = [
    {
        title: 'Saldo legal',
        text: 'Días mínimos por antigüedad (LFT art. 76) más prestaciones de la política.',
        icon: Scale,
    },
    {
        title: 'Días laborables',
        text: 'Sólo se descuentan días programados; descansos y festivos no cuentan.',
        icon: CalendarDays,
    },
    {
        title: 'Cobertura',
        text: 'El calendario marca días con varias ausencias en la misma sucursal.',
        icon: Users,
    },
];
</script>

<template>
    <PageHeader
        title="Vacaciones"
        description="Saldos, solicitudes y cobertura del equipo."
        :icon="Palmtree"
        :help="help"
    >
        <template #actions>
            <Button v-if="canRequest" @click="sheetOpen = true">
                <Plus aria-hidden="true" />
                Solicitar
            </Button>
        </template>
    </PageHeader>

    <div class="mb-5 grid gap-4 sm:grid-cols-2">
        <KpiTile
            label="Por aprobar"
            :value="counts.pending"
            :icon="ListChecks"
            tone="warning"
            hint="Solicitudes pendientes"
        />
        <KpiTile
            label="Próximas aprobadas"
            :value="counts.approved_upcoming"
            :icon="CalendarRange"
            tone="success"
            hint="Aún no terminan"
        />
    </div>

    <nav
        aria-label="Vistas"
        class="mb-4 inline-flex w-full items-center gap-1 overflow-x-auto rounded-xl border bg-surface-2 p-1 sm:w-auto"
    >
        <button
            v-for="t in tabs"
            :key="t.key"
            type="button"
            :aria-current="t.key === tab ? 'page' : undefined"
            :class="
                cn(
                    'inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-lg px-3.5 text-sm font-medium whitespace-nowrap transition-colors sm:flex-none',
                    t.key === tab
                        ? 'bg-card text-foreground shadow-elev-1 ring-1 ring-border'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="
                visitWith({
                    tab: t.key === 'solicitudes' ? null : t.key,
                    sort: null,
                    direction: null,
                })
            "
        >
            <component :is="t.icon" class="size-4" aria-hidden="true" />
            {{ t.label }}
        </button>
    </nav>

    <FilterBar :active-count="activeCount" class="mb-4" @reset="reset">
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
        <template v-if="tab === 'solicitudes'">
            <SelectField
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
        </template>
    </FilterBar>

    <DataTable
        v-if="tab === 'solicitudes' && requests"
        :rows="requests.data"
        :columns="requestColumns"
        :pagination="toPaginationMeta(requests)"
        :sort="sortFromQuery()"
        :row-href="(row) => VacationController.show.url(row.id)"
        caption="Solicitudes de vacaciones"
        :empty-icon="Palmtree"
        empty-title="Sin solicitudes"
        empty-description="No hay solicitudes de vacaciones con estos filtros."
    >
        <template #cell-employee="{ row }">
            <EmployeeCell :employee="row.employee" />
        </template>
        <template #cell-starts_on="{ row }">
            <span class="numeric text-sm font-medium"
                >{{ formatDate(row.starts_on, 'date-short') }} –
                {{ formatDate(row.ends_on, 'date-short') }}</span
            >
            <span class="block text-xs text-muted-foreground"
                >Año de servicio {{ row.service_year }}</span
            >
        </template>
        <template #cell-days="{ row }">
            <span class="numeric font-semibold">{{ row.days }}</span>
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

    <DataTable
        v-else-if="tab === 'saldos' && balances"
        :rows="balances.data"
        :columns="balanceColumns"
        :row-key="(row) => row.employee?.id ?? 0"
        :pagination="toPaginationMeta(balances)"
        caption="Saldos de vacaciones"
        :empty-icon="Wallet"
        empty-title="Sin colaboradores"
        empty-description="No hay personal activo en tu alcance con estos filtros."
    >
        <template #cell-employee="{ row }">
            <EmployeeCell :employee="row.employee" />
        </template>
        <template #cell-year="{ row }">
            <template v-if="row.balance">
                <span class="text-sm font-medium"
                    >Año {{ row.balance.service_year }}</span
                >
                <span class="block text-xs text-muted-foreground">
                    {{ formatDate(row.balance.period_starts_on, 'date-short') }}
                    – {{ formatDate(row.balance.period_ends_on, 'date-short') }}
                </span>
            </template>
            <span v-else class="text-xs text-muted-foreground"
                >Sin relación laboral vigente</span
            >
        </template>
        <template #cell-entitled="{ row }">
            <TooltipProvider v-if="row.balance">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span
                            class="inline-flex cursor-help items-center gap-1 numeric font-semibold"
                        >
                            {{ row.balance.entitled_days }}
                            <Info
                                class="size-3.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs text-xs">
                        <p>
                            Legales: {{ row.balance.legal_days }} · Adicionales:
                            {{ row.balance.extra_days }}
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            Fuente: {{ row.balance.rule_source }}
                        </p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <span v-else>—</span>
        </template>
        <template #cell-meter="{ row }">
            <BalanceMeter
                v-if="row.balance && row.balance.entitled_days > 0"
                :balance="row.balance"
                compact
            />
            <span v-else class="text-xs text-muted-foreground"
                >Sin derecho aún</span
            >
        </template>
        <template #cell-available="{ row }">
            <span
                v-if="row.balance"
                :class="
                    cn(
                        'numeric text-base font-bold',
                        row.balance.available_days > 0
                            ? 'text-success-soft-foreground'
                            : 'text-muted-foreground',
                    )
                "
                >{{ row.balance.available_days }}</span
            >
            <span
                v-if="row.balance && row.balance.pending_days > 0"
                class="block text-[0.6875rem] text-warning-soft-foreground"
            >
                {{ row.balance.pending_days }} pendientes
            </span>
        </template>
        <template #cell-premium="{ row }">
            <span v-if="row.balance" class="numeric text-sm"
                >{{ row.balance.vacation_premium_percent }} %</span
            >
        </template>
    </DataTable>

    <SectionCard v-else-if="tab === 'calendario' && calendar">
        <AbsenceCalendar :calendar="calendar" :today="today" />
    </SectionCard>

    <VacationFormSheet
        v-if="canRequest"
        v-model:open="sheetOpen"
        :employees="options.employees"
        :can-choose-employee="can.create"
        :own-employee-id="own_employee_id"
        :today="today"
    />
</template>
