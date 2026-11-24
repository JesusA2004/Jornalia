<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BookOpenText,
    ExternalLink,
    Gavel,
    Hash,
    ListChecks,
    Scale,
    ShieldAlert,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AttendanceController from '@/actions/App/Http/Controllers/Web/Operacion/AttendanceController';
import ComboboxField from '@/components/app/ComboboxField.vue';
import DataTable from '@/components/app/DataTable.vue';
import DateRangePicker from '@/components/app/DateRangePicker.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import KpiTile from '@/components/app/KpiTile.vue';
import MinutesDisplay from '@/components/app/MinutesDisplay.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/composables/useFilters';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate } from '@/lib/format';
import { toPaginationMeta } from '@/lib/pagination';
import type { DataTableColumn, Tone } from '@/types';
import type {
    ComplianceAlertRow,
    ComplianceIndexProps,
} from '@/types/operacion';
import ComplianceTabs from './components/ComplianceTabs.vue';
import ResolveAlertDialog from './components/ResolveAlertDialog.vue';
import {
    SEVERITY_ICON,
    SEVERITY_LABEL,
    SEVERITY_TONE,
} from './components/severity';
import type { Severity } from './components/severity';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Análisis' },
            { title: 'Cumplimiento', href: '/cumplimiento' },
        ],
    },
});

const props = defineProps<ComplianceIndexProps>();
const { can } = usePermissions();
const canResolve = computed(
    () => props.can.resolve && can('compliance.resolve'),
);

const { filters, activeCount, reset, processing } = useFilters(
    {
        search: props.filters.search ?? '',
        status: props.filters.status as string | null,
        severity: props.filters.severity,
        rule: props.filters.rule,
        branch_id: props.filters.branch_id,
        employee_id: props.filters.employee_id,
        from: props.filters.from,
        to: props.filters.to,
    },
    {
        only: ['alerts', 'summary', 'filters'],
        defaults: { search: '', status: 'open' },
    },
);

const SEVERITIES: Severity[] = ['critical', 'warning', 'info'];

function toggleSeverity(s: Severity): void {
    filters.severity = filters.severity === s ? null : s;
}

const maxRule = computed(() =>
    Math.max(1, ...props.summary.by_rule.map((r) => r.total)),
);

const STATUS_TONE: Record<ComplianceAlertRow['status'], Tone> = {
    open: 'danger',
    acknowledged: 'warning',
    resolved: 'success',
};

const columns: DataTableColumn<ComplianceAlertRow>[] = [
    { key: 'severity', label: 'Severidad', class: 'w-32' },
    { key: 'alert', label: 'Alerta' },
    { key: 'employee', label: 'Colaborador', hideBelow: 'md' },
    { key: 'date', label: 'Fecha', class: 'w-28', hideBelow: 'sm' },
    { key: 'status', label: 'Estado', class: 'w-32' },
    { key: 'actions', label: '', align: 'right', class: 'w-28' },
];

const selected = ref<ComplianceAlertRow | null>(null);
const dialogOpen = ref(false);
function openResolve(alert: ComplianceAlertRow): void {
    selected.value = alert;
    dialogOpen.value = true;
}

const legalRows = computed(() => {
    const p = props.legal?.parameters;
    if (!p) {
        return [];
    }

    return [
        {
            label: 'Jornada ordinaria semanal',
            minutes: p.weekly_ordinary_max_minutes,
        },
        { label: 'Máximo diario — diurna', minutes: p.daily_max_day },
        { label: 'Máximo diario — nocturna', minutes: p.daily_max_night },
        { label: 'Máximo diario — mixta', minutes: p.daily_max_mixed },
        { label: 'Horas extra por día', minutes: p.overtime_daily_max },
        { label: 'Horas extra por semana', minutes: p.overtime_weekly_max },
        { label: 'Triples por semana', minutes: p.overtime_excess_weekly_max },
        { label: 'Jornada total por día', minutes: p.daily_total_max },
        { label: 'Descanso mínimo en jornada', minutes: p.min_break_minutes },
    ];
});

const help = [
    {
        title: 'Alertas automáticas',
        text: 'Se generan al recalcular cada jornada contra la normativa vigente.',
        icon: ShieldAlert,
    },
    {
        title: 'Atender o resolver',
        text: 'Registra una nota; queda en la bitácora inmutable.',
        icon: ListChecks,
    },
    {
        title: 'Normativa',
        text: 'Parámetros de la versión vigente hoy. Cambia sola el 1 de enero de cada tramo; RH la valida en Configuración › Normativa.',
        icon: Scale,
    },
    {
        title: 'Alertas mínimas',
        text: 'Jornada diaria o semanal excedida, tiempo extra cerca o sobre el tope, menores con tiempo extra, sin descanso semanal, omisión sin rectificar más de 2 días y hora del dispositivo distinta.',
        icon: TriangleAlert,
    },
];
</script>

<template>
    <PageHeader
        title="Cumplimiento"
        description="Alertas legales, integridad del registro y bitácora."
        :icon="ShieldCheck"
        :help="help"
    />

    <ComplianceTabs :open-alerts="summary.open" />

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_25rem]">
        <div class="min-w-0 space-y-6">
            <!-- KPIs por severidad (clic = filtrar) -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiTile
                    label="Abiertas"
                    :value="summary.open"
                    :icon="ShieldAlert"
                    :tone="summary.open > 0 ? 'neutral' : 'success'"
                    :hint="
                        summary.open === 0 ? 'Sin pendientes' : 'Sin resolver'
                    "
                />
                <button
                    v-for="s in SEVERITIES"
                    :key="s"
                    type="button"
                    class="rounded-2xl text-left focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none"
                    :aria-pressed="filters.severity === s"
                    @click="toggleSeverity(s)"
                >
                    <KpiTile
                        :label="SEVERITY_LABEL[s]"
                        :value="summary.by_severity[s]"
                        :icon="SEVERITY_ICON[s]"
                        :tone="SEVERITY_TONE[s]"
                        :hint="
                            filters.severity === s ? 'Filtro activo' : 'Filtrar'
                        "
                        :class="[
                            'h-full transition-all hover:-translate-y-px hover:shadow-elev-2',
                            filters.severity === s && 'ring-2 ring-primary',
                        ]"
                    />
                </button>
            </div>

            <FilterBar :active-count="activeCount" @reset="reset">
                <template #search>
                    <SearchInput
                        v-model="filters.search"
                        placeholder="Colaborador o número…"
                    />
                </template>
                <SelectField
                    v-model="filters.status"
                    :options="options.statuses"
                    prefix="Estado:"
                    class="w-full sm:w-44"
                />
                <SelectField
                    v-model="filters.rule"
                    :options="options.rules"
                    placeholder="Regla"
                    prefix="Regla:"
                    clearable
                    class="w-full sm:w-64"
                />
                <SelectField
                    v-if="options.branches.length > 1"
                    v-model="filters.branch_id"
                    :options="options.branches"
                    placeholder="Sucursal"
                    prefix="Sucursal:"
                    clearable
                    class="w-full sm:w-48"
                />
                <ComboboxField
                    v-model="filters.employee_id"
                    :options="options.employees"
                    placeholder="Colaborador"
                    clearable
                    class="w-full sm:w-56"
                />
                <DateRangePicker
                    v-model:start="filters.from"
                    v-model:end="filters.to"
                    class="w-full sm:w-auto"
                />
            </FilterBar>

            <DataTable
                :rows="alerts.data"
                :columns="columns"
                :pagination="toPaginationMeta(alerts)"
                :loading="processing"
                :only="['alerts']"
                caption="Alertas de cumplimiento"
                :empty-icon="BadgeCheck"
                :empty-title="
                    filters.status === 'open' && activeCount === 0
                        ? 'Todo en orden'
                        : 'Sin alertas'
                "
                :empty-description="
                    filters.status === 'open' && activeCount === 0
                        ? 'No hay alertas abiertas en tu alcance.'
                        : 'No hay alertas con estos filtros.'
                "
            >
                <template #cell-severity="{ row }">
                    <span
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-semibold',
                            row.severity === 'critical'
                                ? 'bg-danger text-danger-foreground shadow-elev-1'
                                : row.severity === 'warning'
                                  ? 'bg-warning-soft text-warning-soft-foreground'
                                  : 'bg-info-soft text-info-soft-foreground',
                        ]"
                    >
                        <component
                            :is="SEVERITY_ICON[row.severity]"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ row.severity_label }}
                    </span>
                </template>
                <template #cell-alert="{ row }">
                    <div class="min-w-0 space-y-0.5">
                        <p class="leading-snug font-medium">
                            {{ row.rule_label }}
                        </p>
                        <p class="line-clamp-2 text-xs text-muted-foreground">
                            {{ row.message }}
                        </p>
                        <p
                            v-if="row.legal_basis"
                            class="inline-flex items-center gap-1 text-[0.6875rem] text-brand-strong"
                        >
                            <Gavel class="size-3" aria-hidden="true" />
                            {{ row.legal_basis }}
                        </p>
                        <p
                            v-if="
                                row.data &&
                                typeof row.data.over_limit_minutes === 'number'
                            "
                            class="text-[0.6875rem] text-danger-soft-foreground"
                        >
                            Sobre límite:
                            <MinutesDisplay
                                :minutes="row.data.over_limit_minutes as number"
                            />
                        </p>
                    </div>
                </template>
                <template #cell-employee="{ row }">
                    <Link
                        v-if="row.employee && can('punches.view')"
                        :href="
                            AttendanceController.show({
                                employee: row.employee.id,
                                date: row.date,
                            }).url
                        "
                        class="group inline-flex"
                    >
                        <UserAvatar
                            :name="row.employee.name"
                            :subtitle="
                                row.employee.branch ?? row.employee.number
                            "
                            size="sm"
                            show-name
                            class="group-hover:underline"
                        />
                    </Link>
                    <UserAvatar
                        v-else-if="row.employee"
                        :name="row.employee.name"
                        :subtitle="row.employee.branch ?? row.employee.number"
                        size="sm"
                        show-name
                    />
                    <span v-else class="text-xs text-muted-foreground"
                        >General</span
                    >
                </template>
                <template #cell-date="{ row }">
                    <span class="whitespace-nowrap">{{
                        formatDate(row.date, 'date')
                    }}</span>
                </template>
                <template #cell-status="{ row }">
                    <div class="space-y-0.5">
                        <StatusBadge
                            :label="row.status_label"
                            :tone="STATUS_TONE[row.status]"
                            size="sm"
                        />
                        <p
                            v-if="row.resolved_by"
                            class="truncate text-[0.6875rem] text-muted-foreground"
                        >
                            {{ row.resolved_by }}
                        </p>
                    </div>
                </template>
                <template #cell-actions="{ row }">
                    <Button
                        v-if="row.can_resolve && canResolve"
                        size="sm"
                        variant="outline"
                        @click.stop="openResolve(row)"
                    >
                        Atender
                    </Button>
                    <span
                        v-else-if="row.resolution_note"
                        class="line-clamp-2 max-w-40 text-left text-[0.6875rem] text-muted-foreground"
                        :title="row.resolution_note"
                        >{{ row.resolution_note }}</span
                    >
                </template>
            </DataTable>
        </div>

        <aside class="min-w-0 space-y-6">
            <SectionCard title="Por regla" :icon="TriangleAlert" divided>
                <EmptyState
                    v-if="summary.by_rule.length === 0"
                    :icon="BadgeCheck"
                    size="sm"
                    tone="success"
                    title="Sin alertas abiertas"
                />
                <ul v-else class="space-y-3">
                    <li v-for="r in summary.by_rule" :key="r.rule">
                        <button
                            type="button"
                            class="group w-full space-y-1.5 text-left"
                            @click="
                                filters.rule =
                                    filters.rule === r.rule ? null : r.rule
                            "
                        >
                            <span
                                class="flex items-center justify-between gap-3 text-sm"
                            >
                                <span
                                    :class="[
                                        'truncate group-hover:underline',
                                        filters.rule === r.rule &&
                                            'font-semibold text-primary-soft-foreground',
                                    ]"
                                    >{{ r.label }}</span
                                >
                                <span class="numeric font-semibold">{{
                                    r.total
                                }}</span>
                            </span>
                            <span
                                class="block h-1.5 overflow-hidden rounded-full bg-surface-3"
                            >
                                <span
                                    class="block h-full rounded-full bg-warning transition-all"
                                    :style="{
                                        width: `${(r.total / maxRule) * 100}%`,
                                    }"
                                />
                            </span>
                        </button>
                    </li>
                </ul>
            </SectionCard>

            <SectionCard
                v-if="integrity"
                title="Integridad del registro"
                :icon="Hash"
                divided
            >
                <div class="space-y-2 text-sm">
                    <StatusBadge
                        :status="
                            integrity.valid === null
                                ? 'pending'
                                : integrity.valid
                                  ? 'valid'
                                  : 'invalid'
                        "
                        :label="
                            integrity.valid === null
                                ? 'Sin verificar'
                                : integrity.valid
                                  ? 'Cadena íntegra'
                                  : 'Con hallazgos'
                        "
                        size="sm"
                    />
                    <p class="text-xs text-muted-foreground">
                        Verificación automática diaria ·
                        {{
                            integrity.last_verification_at
                                ? formatDate(
                                      integrity.last_verification_at,
                                      'date',
                                  )
                                : '—'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Anclaje externo automático ·
                        {{
                            integrity.last_anchor_at
                                ? formatDate(integrity.last_anchor_at, 'date')
                                : '—'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        Marcajes: {{ integrity.punches }} · Ajustes:
                        {{ integrity.adjustments }}
                    </p>
                    <Link
                        href="/cumplimiento/integridad"
                        class="text-xs font-medium text-primary hover:underline"
                        >Ver detalle</Link
                    >
                </div>
            </SectionCard>

            <SectionCard title="Normativa vigente" :icon="Scale" divided>
                <EmptyState
                    v-if="!legal"
                    :icon="BookOpenText"
                    size="sm"
                    tone="warning"
                    title="Sin versión legal vigente"
                    description="Carga los parámetros legales para evaluar el cumplimiento."
                />
                <div v-else class="space-y-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ legal.code }}
                            </p>
                            <p class="leading-snug font-semibold">
                                {{ legal.name ?? 'Versión legal' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Vigente desde
                                {{
                                    legal.valid_from
                                        ? formatDate(legal.valid_from, 'date')
                                        : '—'
                                }}
                                <template v-if="legal.valid_to">
                                    hasta
                                    {{
                                        formatDate(legal.valid_to, 'date')
                                    }}</template
                                >
                            </p>
                        </div>
                        <StatusBadge
                            :label="
                                legal.verified
                                    ? 'Verificada por RH'
                                    : 'Sin verificar'
                            "
                            :tone="legal.verified ? 'success' : 'warning'"
                            size="sm"
                        />
                    </div>
                    <p
                        v-if="legal.verified && legal.verified_by"
                        class="text-xs text-muted-foreground"
                    >
                        {{ legal.verified_by }} ·
                        {{ formatDate(legal.verified_at, 'date') }}
                    </p>

                    <dl class="divide-y rounded-xl border">
                        <div
                            v-for="row in legalRows"
                            :key="row.label"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd class="font-semibold">
                                <MinutesDisplay :minutes="row.minutes" />
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Días con horas extra / semana
                            </dt>
                            <dd class="numeric font-semibold">
                                {{
                                    legal.parameters.overtime_max_days_per_week
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Pago extra (dobles / triples)
                            </dt>
                            <dd class="numeric font-semibold">
                                ×{{
                                    legal.parameters.overtime_double_multiplier
                                }}
                                / ×{{
                                    legal.parameters.overtime_excess_multiplier
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Ventana nocturna
                            </dt>
                            <dd class="numeric font-semibold">
                                {{ legal.parameters.night_start }}–{{
                                    legal.parameters.night_end
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Descanso semanal
                            </dt>
                            <dd class="numeric font-semibold">
                                {{ legal.parameters.rest_days_per_week }} día(s)
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Prima dominical mínima
                            </dt>
                            <dd class="numeric font-semibold">
                                {{
                                    legal.parameters.sunday_premium_min_percent
                                }}
                                %
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Prima vacacional mínima
                            </dt>
                            <dd class="numeric font-semibold">
                                {{
                                    legal.parameters
                                        .vacation_premium_min_percent
                                }}
                                %
                            </dd>
                        </div>
                    </dl>

                    <div v-if="legal.sources.length" class="space-y-2">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Fuentes
                        </p>
                        <ul class="space-y-1.5">
                            <li v-for="(s, i) in legal.sources" :key="i">
                                <a
                                    v-if="s.url"
                                    :href="s.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="group flex items-start gap-2 text-sm text-brand-strong hover:underline"
                                >
                                    <ExternalLink
                                        class="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span>{{ s.title }}</span>
                                </a>
                                <span v-else class="text-sm">{{
                                    s.title
                                }}</span>
                            </li>
                        </ul>
                    </div>
                    <p v-if="legal.notes" class="text-xs text-muted-foreground">
                        {{ legal.notes }}
                    </p>
                </div>
            </SectionCard>
        </aside>
    </div>

    <ResolveAlertDialog
        v-if="canResolve"
        v-model:open="dialogOpen"
        :alert="selected"
    />
</template>
