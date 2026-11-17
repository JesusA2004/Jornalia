<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowRight,
    Bot,
    CalendarCheck,
    CalendarClock,
    ClipboardList,
    Clock,
    Coffee,
    DoorOpen,
    FileClock,
    Gauge,
    Hourglass,
    LayoutDashboard,
    PalmtreeIcon,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Timer,
    TrendingUp,
    UserCheck,
    UserX,
    Users,
    WifiOff,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    ChartArea,
    ChartBar,
    ChartDonut,
    DateRangePicker,
    DateTimeDisplay,
    EmptyState,
    KpiTile,
    MinutesDisplay,
    PageHeader,
    SectionCard,
    SelectField,
    StatusBadge,
} from '@/components/app';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { usePermissions } from '@/composables/usePermissions';
import { formatMinutes, formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import WebPunchCard from '@/pages/asistencias/components/WebPunchCard.vue';
import {
    dayMonthShort,
    severityTone,
    workdayTone,
} from '@/pages/asistencias/components/tones';
import { dashboard } from '@/routes';
import asistencias from '@/routes/asistencias';
import cumplimiento from '@/routes/cumplimiento';
import horasExtra from '@/routes/horas-extra';
import incidencias from '@/routes/incidencias';
import rectificaciones from '@/routes/rectificaciones';
import vacaciones from '@/routes/vacaciones';
import type { DashboardProps } from '@/types/operacion';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Panel', href: '/dashboard' },
        ],
    },
});

const props = defineProps<DashboardProps>();
const { can } = usePermissions();

const period = ref(props.filters.period);
const range = ref({ start: props.filters.from, end: props.filters.to });
const branch = ref<string | null>(props.filters.branch_id);

function load(extra: Record<string, string | null> = {}): void {
    router.get(
        dashboard.url(),
        Object.fromEntries(
            Object.entries({
                period: period.value,
                from: period.value === 'custom' ? range.value.start : null,
                to: period.value === 'custom' ? range.value.end : null,
                branch_id: branch.value,
                ...extra,
            }).filter(([, v]) => v !== null && v !== ''),
        ),
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setPeriod(value: unknown): void {
    if (
        value === 'today' ||
        value === '7d' ||
        value === '30d' ||
        value === 'custom'
    ) {
        period.value = value;
    }
}

watch(period, (value) => {
    if (value && value !== 'custom') {
        load();
    }
});
watch(branch, () => load());
watch(
    range,
    (value) => {
        if (period.value === 'custom' && value.start && value.end) {
            load();
        }
    },
    { deep: true },
);

const m = computed(() => props.metrics);
const t = computed(() => m.value?.today);

const periodLabel = computed(() => {
    if (!m.value) {
        return '';
    }

    return m.value.period.days === 1
        ? dayMonthShort(m.value.period.from)
        : `${dayMonthShort(m.value.period.from)} – ${dayMonthShort(m.value.period.to)}`;
});

const presentRatio = computed(() =>
    t.value && t.value.scheduled > 0
        ? Math.min(100, Math.round((t.value.present * 100) / t.value.scheduled))
        : 0,
);

const trendSeries = [
    {
        key: 'punctuality_rate',
        label: 'Puntualidad %',
        color: 'var(--chart-1)',
    },
];
const trendCounts = [
    { key: 'punctual', label: 'Puntuales', color: 'var(--chart-1)' },
    { key: 'late', label: 'Retardos', color: 'var(--warning)' },
    { key: 'absent', label: 'Faltas', color: 'var(--danger)' },
];
const hasTrend = computed(() =>
    (m.value?.trend ?? []).some((d) => d.attended > 0 || d.absent > 0),
);

const branchRows = computed(() =>
    (m.value?.branches ?? []).slice(0, 10).map((b) => ({
        name: b.name,
        worked: Math.round(b.worked_minutes / 6) / 10,
        late: b.late,
        absent: b.absent,
    })),
);

const severityData = computed(() => {
    const s = m.value?.compliance.by_severity;

    return s
        ? [
              { label: 'Críticas', value: s.critical, color: 'var(--danger)' },
              {
                  label: 'Advertencias',
                  value: s.warning,
                  color: 'var(--warning)',
              },
              { label: 'Informativas', value: s.info, color: 'var(--info)' },
          ].filter((d) => d.value > 0)
        : [];
});

const scoreTone = computed(() => {
    const score = m.value?.compliance.score ?? 100;

    return score >= 90 ? 'success' : score >= 70 ? 'warning' : 'danger';
});

const pendingItems = computed(() => {
    const p = m.value?.pending;
    if (!p) {
        return [];
    }

    return [
        {
            key: 'rect',
            label: 'Rectificaciones',
            value: p.rectifications,
            icon: FileClock,
            href: rectificaciones.index.url(),
            permission: 'rectifications.view',
        },
        {
            key: 'inc',
            label: 'Incidencias',
            value: p.incidences,
            icon: ClipboardList,
            href: incidencias.index.url(),
            permission: 'incidences.view',
        },
        {
            key: 'vac',
            label: 'Vacaciones',
            value: p.vacations,
            icon: PalmtreeIcon,
            href: vacaciones.index.url(),
            permission: 'vacations.view',
        },
        {
            key: 'ot',
            label: 'Horas extra',
            value: p.overtime,
            icon: Timer,
            href: horasExtra.index.url(),
            permission: 'overtime.view',
        },
    ].filter((i) => can(i.permission));
});

const personalSeries = computed(() =>
    (props.personal?.series ?? []).map((d) => ({
        date: d.date,
        worked_hours: d.worked_hours,
    })),
);

const help = [
    {
        title: 'Periodo',
        text: 'Elige hoy, 7 o 30 días, o un rango. Las variaciones comparan con el periodo anterior de igual duración.',
        icon: CalendarClock,
    },
    {
        title: 'Datos reales',
        text: 'Las cifras salen de las jornadas calculadas y los marcajes registrados; nada se estima en pantalla.',
        icon: ShieldCheck,
    },
    {
        title: 'Cumplimiento',
        text: 'El índice resume alertas abiertas ponderadas por severidad. No sustituye una revisión legal.',
        icon: Gauge,
    },
];
</script>

<template>
    <PageHeader
        title="Panel"
        :description="
            mode === 'executive'
                ? 'Operación de tu equipo en tiempo real.'
                : 'Tu jornada y tus solicitudes.'
        "
        :icon="LayoutDashboard"
        :help="help"
    >
        <template v-if="mode === 'executive'" #actions>
            <div class="flex flex-wrap items-center gap-2">
                <SelectField
                    v-if="branches.length > 1"
                    v-model="branch"
                    :options="branches"
                    placeholder="Todas las sucursales"
                    clearable
                    size="sm"
                    class="w-48"
                />
                <ToggleGroup
                    :model-value="period"
                    type="single"
                    variant="outline"
                    size="sm"
                    aria-label="Periodo"
                    @update:model-value="setPeriod"
                >
                    <ToggleGroupItem value="today">Hoy</ToggleGroupItem>
                    <ToggleGroupItem value="7d">7 días</ToggleGroupItem>
                    <ToggleGroupItem value="30d">30 días</ToggleGroupItem>
                    <ToggleGroupItem value="custom">Rango</ToggleGroupItem>
                </ToggleGroup>
                <DateRangePicker
                    v-if="period === 'custom'"
                    v-model:start="range.start"
                    v-model:end="range.end"
                    :max="filters.to"
                    class="w-64"
                />
            </div>
        </template>
    </PageHeader>

    <!-- ═══════════════ Vista ejecutiva ═══════════════ -->
    <div v-if="mode === 'executive' && m && t" class="flex flex-col gap-6">
        <!-- Hoy -->
        <section
            class="relative overflow-hidden rounded-3xl border bg-card p-5 shadow-elev-1 sm:p-6"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-20 size-72 rounded-full bg-primary/10 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="relative flex flex-col gap-6 lg:flex-row lg:items-center"
            >
                <div class="flex min-w-0 flex-col gap-3 lg:w-80">
                    <p
                        class="flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        <span class="relative flex size-2">
                            <span
                                class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60"
                            />
                            <span
                                class="relative inline-flex size-2 rounded-full bg-success"
                            />
                        </span>
                        Hoy · {{ dayMonthShort(t.date) }}
                    </p>
                    <div class="flex items-baseline gap-2">
                        <span
                            class="font-display numeric text-5xl font-bold tracking-tight"
                            >{{ formatNumber(t.present) }}</span
                        >
                        <span class="text-muted-foreground"
                            >de
                            {{ formatNumber(t.scheduled) }} programados</span
                        >
                    </div>
                    <Progress :model-value="presentRatio" class="h-2" />
                    <p class="text-sm text-muted-foreground">
                        {{ presentRatio }} % presente ·
                        {{ formatNumber(t.pending_arrival) }} por llegar ·
                        {{ formatNumber(t.active_employees) }} activos
                    </p>
                </div>
                <div
                    class="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
                >
                    <div
                        v-for="item in [
                            {
                                label: 'Ausentes',
                                value: t.absent,
                                icon: UserX,
                                tone: 'text-danger-soft-foreground bg-danger-soft',
                            },
                            {
                                label: 'Retardos',
                                value: t.late,
                                icon: AlarmClock,
                                tone: 'text-warning-soft-foreground bg-warning-soft',
                            },
                            {
                                label: 'Sin salida',
                                value: t.open_sessions,
                                icon: DoorOpen,
                                tone: 'text-info-soft-foreground bg-info-soft',
                            },
                            {
                                label: 'En descanso',
                                value: t.on_break,
                                icon: Coffee,
                                tone: 'text-neutral-soft-foreground bg-neutral-soft',
                            },
                            {
                                label: 'En revisión',
                                value: t.flagged_punches,
                                icon: ShieldAlert,
                                tone: 'text-warning-soft-foreground bg-warning-soft',
                            },
                            {
                                label: 'Marcajes',
                                value: t.punches,
                                icon: Clock,
                                tone: 'text-primary-soft-foreground bg-primary-soft',
                            },
                        ]"
                        :key="item.label"
                        class="flex flex-col gap-2 rounded-2xl border bg-surface-2/60 p-3"
                    >
                        <span
                            :class="
                                cn(
                                    'flex size-7 items-center justify-center rounded-lg',
                                    item.tone,
                                )
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="font-display numeric text-2xl font-bold">{{
                            formatNumber(item.value)
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            item.label
                        }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Periodo -->
        <div>
            <p
                class="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Periodo · {{ periodLabel }}
            </p>
            <div
                class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3 2xl:grid-cols-6"
            >
                <KpiTile
                    label="Horas trabajadas"
                    :value="formatMinutes(m.totals.worked_minutes, 'decimal')"
                    :icon="Clock"
                    :delta="m.deltas.worked_minutes"
                    :sparkline="m.trend.map((d) => d.worked_hours)"
                    :href="
                        can('punches.view')
                            ? asistencias.index.url()
                            : undefined
                    "
                />
                <KpiTile
                    label="Puntualidad"
                    :value="
                        m.totals.punctuality_rate === null
                            ? null
                            : formatPercent(m.totals.punctuality_rate)
                    "
                    :icon="TrendingUp"
                    tone="success"
                    :delta="m.deltas.punctuality_rate"
                    delta-format="number"
                    delta-label="pp vs. periodo anterior"
                    :sparkline="m.trend.map((d) => d.punctuality_rate ?? 0)"
                />
                <KpiTile
                    label="Faltas"
                    :value="m.totals.absent_days"
                    unit="jornadas"
                    :icon="UserX"
                    tone="danger"
                    :delta="m.deltas.absent_days"
                    :positive-is-good="false"
                    :sparkline="m.trend.map((d) => d.absent)"
                />
                <KpiTile
                    label="Excedente registrado"
                    :value="formatMinutes(m.totals.excess_minutes, 'decimal')"
                    :icon="Hourglass"
                    tone="info"
                    :delta="m.deltas.excess_minutes"
                    :positive-is-good="false"
                    :href="
                        can('overtime.view')
                            ? horasExtra.index.url()
                            : undefined
                    "
                />
                <KpiTile
                    label="Marcajes en revisión"
                    :value="m.totals.flagged_punches"
                    :hint="`${formatNumber(m.totals.offline_punches)} sin conexión · ${formatNumber(m.totals.punches)} totales`"
                    :icon="WifiOff"
                    tone="warning"
                    :delta="m.deltas.flagged_punches"
                    :positive-is-good="false"
                />
                <KpiTile
                    label="Salidas automáticas"
                    :value="m.totals.auto_clock_outs"
                    :hint="`${formatMinutes(m.totals.after_cutoff_minutes)} después del cierre (no reconocido)`"
                    :icon="Bot"
                    tone="neutral"
                    :href="
                        can('punches.view')
                            ? asistencias.index.url({
                                  query: {
                                      auto: '1',
                                      from: filters.from,
                                      to: filters.to,
                                  },
                              })
                            : undefined
                    "
                />
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <SectionCard
                title="Tendencia de puntualidad"
                :icon="TrendingUp"
                class="xl:col-span-2"
            >
                <template v-if="hasTrend">
                    <ChartArea
                        :data="m.trend"
                        index="date"
                        :series="trendSeries"
                        :x-formatter="(v) => dayMonthShort(String(v))"
                        :value-formatter="(v) => `${formatNumber(v)} %`"
                        :show-legend="false"
                        height-class="h-56"
                    />
                    <ChartBar
                        :data="m.trend"
                        index="date"
                        :series="trendCounts"
                        stacked
                        :x-formatter="(v) => dayMonthShort(String(v))"
                        height-class="h-40"
                        class="mt-4"
                    />
                </template>
                <EmptyState
                    v-else
                    :icon="CalendarCheck"
                    size="sm"
                    bordered
                    title="Sin jornadas en el periodo"
                    description="Aparecerán al registrarse marcajes."
                />
            </SectionCard>

            <SectionCard title="Cumplimiento" :icon="ShieldCheck">
                <template #actions>
                    <Button
                        v-if="can('compliance.view')"
                        variant="ghost"
                        size="sm"
                        as-child
                    >
                        <Link :href="cumplimiento.index.url()">
                            Ver <ArrowRight class="size-3.5" />
                        </Link>
                    </Button>
                </template>
                <div class="flex flex-col gap-4">
                    <div class="flex items-center gap-4">
                        <div
                            :class="
                                cn(
                                    'flex size-20 shrink-0 flex-col items-center justify-center rounded-2xl',
                                    scoreTone === 'success' &&
                                        'bg-success-soft text-success-soft-foreground',
                                    scoreTone === 'warning' &&
                                        'bg-warning-soft text-warning-soft-foreground',
                                    scoreTone === 'danger' &&
                                        'bg-danger-soft text-danger-soft-foreground',
                                )
                            "
                        >
                            <span
                                class="font-display numeric text-3xl font-bold"
                                >{{ m.compliance.score ?? '—' }}</span
                            >
                            <span class="text-[0.65rem] font-semibold uppercase"
                                >índice</span
                            >
                        </div>
                        <div class="min-w-0">
                            <p class="font-semibold">
                                {{ formatNumber(m.compliance.open) }} alertas
                                abiertas
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ m.compliance.by_severity.critical }} críticas
                                ·
                                {{ m.compliance.by_severity.warning }}
                                advertencias
                            </p>
                        </div>
                    </div>
                    <ChartDonut
                        v-if="severityData.length > 0"
                        :data="severityData"
                        index="label"
                        category="value"
                        color-key="color"
                        :center-value="formatNumber(m.compliance.open)"
                        center-label="abiertas"
                        height-class="h-40"
                    />
                    <EmptyState
                        v-else
                        :icon="Sparkles"
                        tone="success"
                        size="sm"
                        title="Sin alertas abiertas"
                    />
                </div>
            </SectionCard>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <SectionCard
                title="Por sucursal"
                :icon="Users"
                class="xl:col-span-2"
            >
                <ChartBar
                    v-if="branchRows.length > 0"
                    :data="branchRows"
                    index="name"
                    :series="[
                        {
                            key: 'worked',
                            label: 'Horas trabajadas',
                            color: 'var(--chart-1)',
                        },
                    ]"
                    :value-formatter="(v) => `${formatNumber(v)} h`"
                    :show-legend="false"
                    height-class="h-56"
                />
                <div v-if="m.branches.length > 0" class="mt-4 overflow-x-auto">
                    <table
                        class="w-full min-w-[34rem] text-sm [&_td+td]:pl-4 [&_th]:whitespace-nowrap [&_th+th]:pl-4"
                    >
                        <thead>
                            <tr class="text-left text-xs text-muted-foreground">
                                <th class="py-2 font-medium">Sucursal</th>
                                <th class="py-2 text-right font-medium">
                                    Personas
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Puntualidad
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Retardos
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Faltas
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Excedente
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="b in m.branches" :key="b.name">
                                <td class="py-2 font-medium">{{ b.name }}</td>
                                <td class="py-2 text-right numeric">
                                    {{ b.employees }}
                                </td>
                                <td class="py-2 text-right numeric">
                                    {{ formatPercent(b.punctuality_rate) }}
                                </td>
                                <td class="py-2 text-right numeric">
                                    {{ b.late }}
                                </td>
                                <td class="py-2 text-right numeric">
                                    {{ b.absent }}
                                </td>
                                <td class="py-2 text-right numeric">
                                    <MinutesDisplay
                                        :minutes="b.excess_minutes"
                                        format="short"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState
                    v-else
                    :icon="Users"
                    size="sm"
                    bordered
                    title="Sin datos por sucursal"
                />
            </SectionCard>

            <div class="flex flex-col gap-6">
                <SectionCard title="Horas extra" :icon="Timer">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-surface-2 p-3">
                            <p class="text-xs text-muted-foreground">Dobles</p>
                            <MinutesDisplay
                                :minutes="m.overtime.double_minutes"
                                format="short"
                                class="font-display text-xl font-bold"
                            />
                        </div>
                        <div class="rounded-xl bg-surface-2 p-3">
                            <p class="text-xs text-muted-foreground">Triples</p>
                            <MinutesDisplay
                                :minutes="m.overtime.triple_minutes"
                                format="short"
                                class="font-display text-xl font-bold"
                            />
                        </div>
                        <div
                            :class="
                                cn(
                                    'col-span-2 flex items-center justify-between rounded-xl p-3',
                                    m.overtime.over_limit_minutes > 0
                                        ? 'bg-danger-soft text-danger-soft-foreground ring-1 ring-danger/30'
                                        : 'bg-surface-2',
                                )
                            "
                        >
                            <span
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <ShieldAlert
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Sobre límite legal
                            </span>
                            <MinutesDisplay
                                :minutes="m.overtime.over_limit_minutes"
                                format="short"
                                class="font-display text-lg font-bold"
                            />
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Autorizado
                        <MinutesDisplay
                            :minutes="m.overtime.authorized_minutes"
                            format="short"
                        />
                        de
                        <MinutesDisplay
                            :minutes="m.overtime.recorded_minutes"
                            format="short"
                        />
                        · {{ m.overtime.pending }} pendientes
                    </p>
                </SectionCard>

                <SectionCard
                    title="Por resolver"
                    :icon="ClipboardList"
                    padding="sm"
                >
                    <ul class="flex flex-col">
                        <li v-for="item in pendingItems" :key="item.key">
                            <Link
                                :href="item.href"
                                class="flex items-center gap-3 rounded-xl px-2 py-2.5 transition-colors hover:bg-surface-2"
                            >
                                <span
                                    class="flex size-8 items-center justify-center rounded-lg bg-surface-3"
                                >
                                    <component
                                        :is="item.icon"
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span class="flex-1 text-sm font-medium">{{
                                    item.label
                                }}</span>
                                <span
                                    :class="
                                        cn(
                                            'rounded-full px-2 py-0.5 numeric text-xs font-semibold',
                                            item.value > 0
                                                ? 'bg-warning-soft text-warning-soft-foreground'
                                                : 'bg-neutral-soft text-muted-foreground',
                                        )
                                    "
                                    >{{ item.value }}</span
                                >
                                <ArrowRight
                                    class="size-3.5 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </SectionCard>
            </div>
        </div>

        <SectionCard
            v-if="m.compliance.recent.length > 0"
            title="Alertas laborales recientes"
            :icon="ShieldAlert"
            padding="none"
        >
            <ul class="divide-y">
                <li
                    v-for="alert in m.compliance.recent"
                    :key="alert.id"
                    class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:gap-4"
                >
                    <StatusBadge
                        :label="alert.severity_label"
                        :tone="severityTone(alert.severity)"
                        size="sm"
                        class="w-fit"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ alert.message }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ alert.employee?.name ?? 'General' }} ·
                            {{ dayMonthShort(alert.date) }}
                            <template v-if="alert.legal_basis">
                                · {{ alert.legal_basis }}</template
                            >
                        </p>
                    </div>
                </li>
            </ul>
        </SectionCard>

        <!-- Mi jornada (compacta) -->
        <SectionCard v-if="personal" title="Mi jornada" :icon="UserCheck">
            <div
                class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
            >
                <div class="text-sm">
                    <p class="font-medium">
                        <template v-if="personal.today.shift">
                            Turno
                            <DateTimeDisplay
                                :value="personal.today.shift.starts_at"
                                :timezone="personal.today.timezone"
                                format="time"
                            />
                            –
                            <DateTimeDisplay
                                :value="personal.today.shift.ends_at"
                                :timezone="personal.today.timezone"
                                format="time"
                            />
                        </template>
                        <template v-else>Sin turno hoy</template>
                    </p>
                    <p class="text-muted-foreground">
                        {{ personal.today.events.length }} marcajes ·
                        <MinutesDisplay
                            :minutes="personal.today.worked_minutes"
                        />
                    </p>
                </div>
                <WebPunchCard :state="personal.web_punch" />
            </div>
        </SectionCard>
    </div>

    <!-- ═══════════════ Vista personal ═══════════════ -->
    <div v-else-if="personal" class="flex flex-col gap-6">
        <section
            class="relative overflow-hidden rounded-3xl border bg-card p-6 shadow-elev-1"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-primary/10 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="relative grid gap-6 lg:grid-cols-[1fr_auto] lg:items-center"
            >
                <div class="flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Hoy · {{ dayMonthShort(personal.today.date) }}
                    </p>
                    <h2 class="font-display text-3xl font-bold">
                        Hola, {{ personal.employee.name.split(' ')[0] }}
                    </h2>
                    <p class="text-muted-foreground">
                        <template v-if="personal.today.holiday"
                            >Festivo: {{ personal.today.holiday }}</template
                        >
                        <template v-else-if="personal.today.shift">
                            Turno
                            <DateTimeDisplay
                                :value="personal.today.shift.starts_at"
                                :timezone="personal.today.timezone"
                                format="time"
                            />
                            –
                            <DateTimeDisplay
                                :value="personal.today.shift.ends_at"
                                :timezone="personal.today.timezone"
                                format="time"
                            />
                            · {{ personal.today.shift.schedule_name }}
                        </template>
                        <template v-else
                            >Hoy no tienes turno programado.</template
                        >
                    </p>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <StatusBadge
                            v-if="personal.today.status"
                            :label="personal.today.status_label ?? ''"
                            :tone="workdayTone(personal.today.status)"
                        />
                        <span class="text-sm text-muted-foreground">
                            Trabajado
                            <MinutesDisplay
                                :minutes="personal.today.worked_minutes"
                            />
                            <template v-if="personal.today.late_minutes > 0">
                                · retardo
                                <MinutesDisplay
                                    :minutes="personal.today.late_minutes"
                                />
                            </template>
                        </span>
                    </div>
                </div>
                <WebPunchCard :state="personal.web_punch" />
            </div>

            <ol
                v-if="personal.today.events.length > 0"
                class="relative mt-6 flex flex-wrap gap-2"
            >
                <li
                    v-for="(e, i) in personal.today.events"
                    :key="i"
                    class="flex items-center gap-2 rounded-full border bg-surface-2 px-3 py-1.5 text-sm"
                >
                    <span
                        :class="
                            cn(
                                'size-2 rounded-full',
                                e.type === 'clock_in'
                                    ? 'bg-success'
                                    : e.type === 'clock_out'
                                      ? 'bg-danger'
                                      : 'bg-warning',
                            )
                        "
                    />
                    {{ e.type_label }}
                    <DateTimeDisplay
                        :value="e.time"
                        :timezone="personal.today.timezone"
                        format="time"
                        class="numeric font-semibold"
                    />
                    <span
                        v-if="e.origin !== 'original'"
                        class="text-xs text-info-soft-foreground"
                        >rectificado</span
                    >
                </li>
            </ol>
        </section>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <KpiTile
                label="Trabajado 14 días"
                :value="
                    formatMinutes(
                        personal.period_totals.worked_minutes,
                        'decimal',
                    )
                "
                :icon="Clock"
            />
            <KpiTile
                label="Retardos"
                :value="personal.period_totals.late_days"
                unit="días"
                :hint="formatMinutes(personal.period_totals.late_minutes)"
                :icon="AlarmClock"
                tone="warning"
            />
            <KpiTile
                label="Excedente"
                :value="
                    formatMinutes(
                        personal.period_totals.excess_minutes,
                        'decimal',
                    )
                "
                :icon="Hourglass"
                tone="info"
            />
            <KpiTile
                label="Vacaciones disponibles"
                :value="personal.vacation?.available_days ?? null"
                unit="días"
                :hint="personal.vacation?.rule_source"
                :icon="PalmtreeIcon"
                tone="success"
            />
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <SectionCard
                title="Últimos 14 días"
                :icon="CalendarCheck"
                class="xl:col-span-2"
            >
                <ChartBar
                    v-if="personalSeries.some((d) => d.worked_hours > 0)"
                    :data="personalSeries"
                    index="date"
                    :series="[
                        {
                            key: 'worked_hours',
                            label: 'Horas',
                            color: 'var(--chart-1)',
                        },
                    ]"
                    :x-formatter="(v) => dayMonthShort(String(v))"
                    :value-formatter="(v) => `${formatNumber(v)} h`"
                    :show-legend="false"
                    height-class="h-56"
                />
                <EmptyState
                    v-else
                    :icon="CalendarCheck"
                    size="sm"
                    bordered
                    title="Aún sin jornadas registradas"
                />
            </SectionCard>

            <SectionCard title="Mis solicitudes" :icon="ClipboardList">
                <dl class="flex flex-col gap-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-muted-foreground">
                            Rectificaciones pendientes
                        </dt>
                        <dd class="numeric font-semibold">
                            {{ personal.pending.rectifications }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-muted-foreground">
                            Incidencias pendientes
                        </dt>
                        <dd class="numeric font-semibold">
                            {{ personal.pending.incidences }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-muted-foreground">
                            Vacaciones pendientes
                        </dt>
                        <dd class="numeric font-semibold">
                            {{ personal.pending.vacations }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="personal.vacation"
                    class="mt-5 rounded-xl bg-surface-2 p-3 text-xs text-muted-foreground"
                >
                    Año de servicio {{ personal.vacation.service_year }}:
                    {{ personal.vacation.entitled_days }} días ({{
                        personal.vacation.used_days
                    }}
                    usados, {{ personal.vacation.pending_days }} en trámite).
                    {{ personal.vacation.rule_source }}.
                </div>
            </SectionCard>
        </div>
    </div>

    <EmptyState
        v-else
        :icon="LayoutDashboard"
        tone="primary"
        bordered
        class="rounded-2xl"
        title="Sin expediente vinculado"
        description="Tu usuario aún no está asociado a un colaborador."
    />
</template>
