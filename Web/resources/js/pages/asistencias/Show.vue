<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Ban,
    CalendarClock,
    CalendarRange,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    FilePenLine,
    Fingerprint,
    Hash,
    Lock,
    MapPin,
    Monitor,
    ShieldAlert,
    Scale,
    Sparkles,
    TriangleAlert,
    WifiOff,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    DateTimeDisplay,
    EmptyState,
    KeyValueGrid,
    KpiTile,
    PageHeader,
    SectionCard,
    StatusBadge,
    Timeline,
    UserAvatar,
} from '@/components/app';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDateTime, formatMinutes } from '@/lib/format';
import { cn } from '@/lib/utils';
import DayRuler from '@/pages/asistencias/components/DayRuler.vue';
import { legendDotClass, workdayLegendKey } from '@/lib/attendance-status';
import {
    approvalTone,
    dayMonthShort,
    punchStatusTone,
    weekdayShort,
    workdayTone,
} from '@/pages/asistencias/components/tones';
import asistencias from '@/routes/asistencias';
import rectificaciones from '@/routes/rectificaciones';
import type { KeyValueItem, TimelineItem } from '@/types';
import type { AttendanceShowProps, PunchDetail } from '@/types/operacion';

const props = defineProps<AttendanceShowProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operación' },
            { title: 'Asistencias', href: '/asistencias' },
            { title: 'Detalle del día' },
        ],
    },
});

const tz = computed(() => props.day.timezone);
const employeeId = computed(() => props.employee?.id ?? 0);
const dayHref = (date: string) =>
    asistencias.show.url({ employee: employeeId.value, date });

const longDate = computed(() =>
    new Intl.DateTimeFormat('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${props.date}T12:00:00Z`)),
);

const rectifyHref = computed(() =>
    rectificaciones.index.url({
        query: {
            nuevo: '1',
            employee_id: String(employeeId.value),
            date: props.date,
        },
    }),
);

function punchItems(p: PunchDetail): KeyValueItem[] {
    const location =
        p.latitude && p.longitude
            ? `${p.latitude}, ${p.longitude}${p.accuracy_meters !== null ? ` (±${p.accuracy_meters} m)` : ''}`
            : 'Sin ubicación';

    return [
        {
            key: 'auth',
            label: 'Autenticación',
            value:
                p.auth_method_label +
                (p.face_verified ? ' · rostro verificado' : ''),
        },
        {
            key: 'source',
            label: 'Origen del marcaje',
            value: p.origin.label,
        },
        { key: 'location', label: 'Ubicación', value: location },
        {
            key: 'geofence',
            label: 'Geocerca',
            value: p.geofence
                ? `${p.geofence.name}${p.distance_meters !== null ? ` · a ${p.distance_meters} m` : ''}`
                : p.distance_meters !== null
                  ? `A ${p.distance_meters} m`
                  : null,
        },
        {
            key: 'device',
            label: 'Dispositivo',
            value: p.is_system
                ? 'Generado por sistema (histórico)'
                : p.device
                  ? `${p.device.name} · ${p.device.platform}${p.device.model ? ` · ${p.device.model}` : ''}`
                  : p.recorded_by
                    ? `Web · ${p.recorded_by}`
                    : null,
        },
        {
            key: 'device_time',
            label: 'Hora del dispositivo',
            value: p.device_time
                ? `${time(p.device_time)} · ${p.clock_skew_seconds ?? 0} s de desfase`
                : null,
        },
        {
            key: 'synced_at',
            label: 'Sincronización',
            value: p.synced_at ? time(p.synced_at) : null,
        },
        {
            key: 'received_at',
            label: 'Recepción en servidor',
            value: time(p.received_at ?? p.server_time),
        },
        { key: 'ip', label: 'IP', value: p.ip_address, mono: true },
        {
            key: 'signature',
            label: 'Firma',
            value: p.is_system
                ? 'No aplica (histórico de sistema)'
                : p.device
                  ? p.signature_valid
                      ? 'Válida'
                      : 'No válida'
                  : 'No aplica (sesión web)',
        },
        {
            key: 'sequence',
            label: 'Secuencia',
            value: `#${p.sequence}`,
            mono: true,
        },
        { key: 'hash', label: 'Hash', value: p.hash, mono: true, full: true },
        {
            key: 'previous',
            label: 'Hash anterior',
            value: p.previous_hash,
            mono: true,
            full: true,
        },
    ];
}

const time = (iso: string | null) =>
    formatDateTime(iso, { format: 'time-seconds', timeZone: tz.value });

const osm = (p: PunchDetail) =>
    `https://www.openstreetmap.org/?mlat=${p.latitude}&mlon=${p.longitude}#map=18/${p.latitude}/${p.longitude}`;

const OMISSION_LABEL: Record<string, string> = {
    missing_clock_out: 'Omisión de salida',
    missing_clock_in: 'Omisión de entrada',
};

const statusLabel = computed(() => {
    if (!props.summary) {
        return 'Sin calcular';
    }
    if (props.summary.has_rectifications) {
        return 'Rectificada*';
    }

    return props.summary.omission
        ? (OMISSION_LABEL[props.summary.omission] ?? props.summary.status_label)
        : props.summary.status_label;
});

const statusTone = computed(() =>
    workdayTone(props.summary?.status, {
        omission: props.summary?.omission,
        rectified: props.summary?.has_rectifications,
    }),
);

const iso = (value: string | null | undefined) =>
    formatDateTime(value ?? null, { format: 'time', timeZone: tz.value });

/** Programado vs registrado vs calculado (sin cálculos: todo viene del dominio). */
const comparison = computed(() => {
    const s = props.summary;
    const shift = props.day.shift;

    return [
        {
            key: 'scheduled',
            title: 'Programado',
            rows: [
                {
                    label: 'Horario',
                    value: shift
                        ? `${iso(shift.starts_at)} – ${iso(shift.ends_at)}`
                        : '—',
                },
                {
                    label: 'Descanso / comida',
                    value: shift ? formatMinutes(shift.break_minutes) : '—',
                },
                {
                    label: 'Jornada',
                    value: shift ? formatMinutes(shift.scheduled_minutes) : '—',
                },
            ],
        },
        {
            key: 'registered',
            title: 'Registrado',
            rows: [
                {
                    label: 'Entrada',
                    value: s?.first_in ? iso(s.first_in) : 'Sin entrada',
                },
                {
                    label: 'Salida',
                    value: s?.last_out ? iso(s.last_out) : 'Sin salida',
                },
                {
                    label: 'Descanso / comida',
                    value: formatMinutes(s?.break_minutes ?? 0),
                },
            ],
        },
        {
            key: 'computed',
            title: 'Calculado',
            rows: [
                {
                    label: 'Trabajado',
                    value: formatMinutes(s?.worked_minutes ?? 0),
                },
                {
                    label: 'Retardo',
                    value: formatMinutes(s?.late_minutes ?? 0),
                },
                {
                    label: 'Tiempo extra',
                    value: formatMinutes(s?.excess_minutes ?? 0),
                },
            ],
        },
    ];
});

const adjustmentItems = computed<TimelineItem[]>(() =>
    props.day.adjustments.map((a) => ({
        id: a.uuid,
        title: `${a.action_label}${a.type_label ? ` · ${a.type_label}` : ''}`,
        description: a.reason,
        at: a.recognized_time ?? a.created_at,
        timezone: tz.value,
        tone:
            a.action === 'void'
                ? 'danger'
                : a.action === 'add'
                  ? 'success'
                  : 'info',
        icon: a.action === 'void' ? Ban : FilePenLine,
        actor: a.approved_by ? `Aprobó ${a.approved_by}` : null,
        meta: `Ajuste #${a.sequence} · solicitud ${a.rectification_request_id}`,
    })),
);

const recognizedItems = computed<TimelineItem[]>(() =>
    props.day.recognized.map((e, i) => ({
        id: `${e.time}-${i}`,
        title: e.type_label,
        description: e.origin_label,
        at: e.time,
        timezone: tz.value,
        tone: e.origin === 'original' ? 'success' : 'info',
        icon: e.origin === 'original' ? CheckCircle2 : Sparkles,
    })),
);

const help = [
    {
        title: 'Original',
        text: 'El marcaje tal como se recibió. Es inmutable y está encadenado por hash.',
        icon: Lock,
    },
    {
        title: 'Rectificación',
        text: 'Ajuste firmado por el colaborador que agrega, reemplaza o excluye un marcaje, sin borrarlo.',
        icon: FilePenLine,
    },
    {
        title: 'Reconocido',
        text: 'La secuencia efectiva que se usa para calcular la jornada.',
        icon: CheckCircle2,
    },
    {
        title: 'Omisión de checada',
        text: 'Falta la entrada o la salida. El sistema no la completa; se corrige con rectificación firmada.',
        icon: TriangleAlert,
    },
];
</script>

<template>
    <PageHeader
        :title="employee?.name ?? 'Colaborador'"
        :description="longDate"
        :back-href="asistencias.index.url()"
        back-label="Asistencias"
        :help="help"
    >
        <template #meta>
            <span class="text-sm text-muted-foreground">
                {{
                    [employee?.number, employee?.branch, employee?.position]
                        .filter(Boolean)
                        .join(' · ')
                }}
            </span>
        </template>
        <template #actions>
            <div class="flex items-center gap-1">
                <Button variant="outline" size="icon" as-child>
                    <Link
                        :href="dayHref(previous_date)"
                        aria-label="Día anterior"
                        preserve-scroll
                    >
                        <ChevronLeft class="size-4" />
                    </Link>
                </Button>
                <Button variant="outline" size="icon" as-child>
                    <Link
                        :href="dayHref(next_date)"
                        aria-label="Día siguiente"
                        preserve-scroll
                    >
                        <ChevronRight class="size-4" />
                    </Link>
                </Button>
            </div>
            <Button v-if="can.rectify" as-child>
                <Link :href="rectifyHref">
                    <FilePenLine class="size-4" />
                    Solicitar rectificación
                </Link>
            </Button>
        </template>
    </PageHeader>

    <!-- Semana -->
    <nav
        v-if="week.length > 0"
        class="mb-6 flex gap-2 overflow-x-auto pb-1"
        aria-label="Días cercanos"
    >
        <Link
            v-for="d in week"
            :key="d.date"
            :href="dayHref(d.date)"
            preserve-scroll
            :class="
                cn(
                    'flex min-w-16 flex-col items-center gap-1 rounded-xl border px-2.5 py-2 text-xs transition-colors hover:bg-surface-2',
                    d.date === date &&
                        'border-primary bg-primary-soft text-primary-soft-foreground',
                )
            "
        >
            <span class="font-medium capitalize">{{
                weekdayShort(d.date)
            }}</span>
            <span class="font-display numeric text-base font-bold">{{
                d.date.slice(8)
            }}</span>
            <span
                :class="
                    cn(
                        'h-1.5 w-6 rounded-full',
                        legendDotClass(workdayLegendKey(d.status)),
                    )
                "
                :title="d.status_label"
            />
        </Link>
    </nav>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <KpiTile
            label="Estado"
            :value="statusLabel"
            :icon="CalendarClock"
            :tone="statusTone"
            :href="summary?.rectification?.url ?? undefined"
            :hint="
                summary?.has_rectifications
                    ? 'Ajuste firmado por el colaborador'
                    : summary?.omission
                      ? 'Requiere rectificación firmada'
                      : undefined
            "
        />
        <KpiTile
            label="Trabajado"
            :value="formatMinutes(summary?.worked_minutes ?? 0)"
            :hint="
                summary
                    ? `Programado ${formatMinutes(summary.scheduled_minutes)}`
                    : undefined
            "
            :icon="Fingerprint"
            tone="success"
        />
        <KpiTile
            label="Retardo"
            :value="formatMinutes(summary?.late_minutes ?? 0)"
            :hint="
                summary?.early_leave_minutes
                    ? `Salida anticipada ${formatMinutes(summary.early_leave_minutes)}`
                    : undefined
            "
            :icon="ShieldAlert"
            tone="warning"
        />
        <KpiTile
            label="Tiempo extra"
            :value="formatMinutes(summary?.excess_minutes ?? 0)"
            :hint="
                summary?.night_minutes
                    ? `Nocturno ${formatMinutes(summary.night_minutes)}`
                    : undefined
            "
            :icon="Sparkles"
            tone="info"
        />
    </div>

    <SectionCard title="Línea del día" :icon="CalendarRange" class="mb-6">
        <template #actions>
            <div
                class="hidden items-center gap-3 text-xs text-muted-foreground sm:flex"
            >
                <span class="flex items-center gap-1"
                    ><span
                        class="size-2 rounded-full bg-success"
                    />Entrada</span
                >
                <span class="flex items-center gap-1"
                    ><span
                        class="size-2 rounded-full bg-warning"
                    />Descanso</span
                >
                <span class="flex items-center gap-1"
                    ><span class="size-2 rounded-full bg-danger" />Salida</span
                >
            </div>
        </template>
        <DayRuler :day="day" />
        <div
            v-if="summary && summary.anomalies.length > 0"
            class="mt-4 flex flex-wrap gap-1.5"
        >
            <span
                v-for="a in summary.anomalies"
                :key="a.code"
                class="rounded-md bg-warning-soft px-2 py-0.5 text-xs text-warning-soft-foreground"
                >{{ a.label }}</span
            >
        </div>
    </SectionCard>

    <!-- Programado vs registrado vs calculado -->
    <SectionCard
        title="Programado · registrado · calculado"
        :icon="Scale"
        class="mb-6"
    >
        <div class="grid gap-4 sm:grid-cols-3">
            <div
                v-for="col in comparison"
                :key="col.key"
                class="rounded-xl border bg-surface-2/40 p-3"
            >
                <p
                    class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ col.title }}
                </p>
                <dl class="flex flex-col gap-1.5 text-sm">
                    <div
                        v-for="r in col.rows"
                        :key="r.label"
                        class="flex items-baseline justify-between gap-3"
                    >
                        <dt class="text-muted-foreground">{{ r.label }}</dt>
                        <dd class="numeric font-medium">{{ r.value }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </SectionCard>

    <div class="grid gap-6 xl:grid-cols-2">
        <!-- Horario programado -->
        <SectionCard title="Horario programado" :icon="CalendarClock">
            <div v-if="day.shift" class="flex flex-col gap-4">
                <div class="flex items-baseline gap-3">
                    <span class="font-display numeric text-3xl font-bold">
                        <DateTimeDisplay
                            :value="day.shift.starts_at"
                            :timezone="tz"
                            format="time"
                        />
                        –
                        <DateTimeDisplay
                            :value="day.shift.ends_at"
                            :timezone="tz"
                            format="time"
                        />
                    </span>
                    <Badge v-if="day.shift.from_exception" variant="outline"
                        >Excepción</Badge
                    >
                </div>
                <KeyValueGrid
                    :items="[
                        {
                            key: 'schedule',
                            label: 'Horario',
                            value: day.shift.schedule_name,
                        },
                        {
                            key: 'kind',
                            label: 'Jornada',
                            value: day.shift.kind_label,
                        },
                        {
                            key: 'scheduled',
                            label: 'Tiempo programado',
                            value: formatMinutes(day.shift.scheduled_minutes),
                        },
                        {
                            key: 'break',
                            label: 'Descanso',
                            value: formatMinutes(day.shift.break_minutes),
                        },
                        {
                            key: 'tolerance',
                            label: 'Tolerancia',
                            value: `${day.shift.tolerance_minutes} min`,
                        },
                    ]"
                />
            </div>
            <EmptyState
                v-else
                :icon="CalendarClock"
                size="sm"
                :title="day.holiday ? day.holiday.name : 'Sin turno programado'"
                :description="
                    day.holiday?.legal_basis ??
                    'Día de descanso o sin horario asignado.'
                "
            />
            <p
                v-if="day.shift && day.holiday"
                class="mt-3 text-sm text-brand-soft-foreground"
            >
                Festivo: {{ day.holiday.name
                }}<template v-if="day.holiday.legal_basis">
                    · {{ day.holiday.legal_basis }}</template
                >
            </p>
        </SectionCard>

        <!-- Horario reconocido -->
        <SectionCard
            title="Horario reconocido"
            description="Secuencia efectiva para el cálculo."
            :icon="CheckCircle2"
        >
            <Timeline
                :items="recognizedItems"
                date-format="time"
                empty-text="Sin eventos reconocidos."
            />
        </SectionCard>
    </div>

    <!-- Marcajes originales -->
    <SectionCard
        title="Marcajes originales"
        description="Registro inmutable, encadenado por hash."
        :icon="Lock"
        class="mt-6"
    >
        <div v-if="day.punches.length > 0" class="flex flex-col gap-3">
            <Collapsible
                v-for="p in day.punches"
                :key="p.uuid"
                v-slot="{ open }"
                :class="
                    cn(
                        'rounded-2xl border bg-surface-2/40',
                        p.voided && 'border-dashed opacity-80',
                        p.status === 'flagged' && 'border-warning/40',
                        p.is_system &&
                            'border-dashed border-warning/50 bg-warning-soft/20',
                    )
                "
            >
                <CollapsibleTrigger
                    class="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left"
                >
                    <span
                        :class="
                            cn(
                                'flex size-9 shrink-0 items-center justify-center rounded-xl',
                                p.is_system
                                    ? 'bg-warning-soft text-warning-soft-foreground'
                                    : p.type === 'clock_in'
                                      ? 'bg-success-soft text-success-soft-foreground'
                                      : p.type === 'clock_out'
                                        ? 'bg-danger-soft text-danger-soft-foreground'
                                        : 'bg-warning-soft text-warning-soft-foreground',
                            )
                        "
                    >
                        <Fingerprint class="size-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            {{ p.type_label }}
                            <DateTimeDisplay
                                :value="p.server_time"
                                :timezone="tz"
                                format="time-seconds"
                                class="font-display numeric text-lg font-bold"
                            />
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ p.origin.label }} · {{ p.auth_method_label }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Badge
                            v-if="p.is_system"
                            variant="outline"
                            class="gap-1 border-warning/40 text-warning-soft-foreground"
                        >
                            No reconocido
                        </Badge>
                        <Badge
                            v-if="p.voided"
                            variant="outline"
                            class="gap-1 border-danger/40 text-danger-soft-foreground"
                        >
                            <Ban class="size-3" aria-hidden="true" /> Excluido
                            del cálculo
                        </Badge>
                        <Badge
                            v-if="p.captured_offline"
                            variant="outline"
                            class="gap-1"
                        >
                            <WifiOff class="size-3" aria-hidden="true" />
                            Offline
                        </Badge>
                        <Badge
                            v-if="!p.has_location && !p.is_system"
                            variant="outline"
                            class="gap-1 text-muted-foreground"
                        >
                            <MapPin class="size-3" aria-hidden="true" /> Sin
                            ubicación
                        </Badge>
                        <StatusBadge
                            :label="
                                p.status === 'flagged'
                                    ? 'En revisión'
                                    : p.status_label
                            "
                            :tone="punchStatusTone(p.status)"
                            size="sm"
                        />
                        <span
                            class="inline-flex items-center gap-0.5 font-mono text-xs text-muted-foreground"
                        >
                            <Hash class="size-3" aria-hidden="true" />{{
                                p.sequence
                            }}
                        </span>
                        <ChevronRight
                            :class="
                                cn(
                                    'size-4 text-muted-foreground transition-transform',
                                    open && 'rotate-90',
                                )
                            "
                            aria-hidden="true"
                        />
                    </div>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div class="border-t px-4 py-4">
                        <div
                            v-if="p.anomalies.length > 0"
                            class="mb-4 flex flex-wrap gap-1.5"
                        >
                            <span
                                v-for="a in p.anomalies"
                                :key="a.code"
                                class="rounded-md bg-warning-soft px-2 py-0.5 text-xs text-warning-soft-foreground"
                                >{{ a.label }}</span
                            >
                        </div>
                        <KeyValueGrid :items="punchItems(p)" :columns="2">
                            <template #value-location="{ item }">
                                <span class="flex flex-wrap items-center gap-2">
                                    {{ item.value }}
                                    <a
                                        v-if="p.latitude && p.longitude"
                                        :href="osm(p)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                    >
                                        Ver mapa
                                        <ExternalLink
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                    </a>
                                    <Badge
                                        v-if="p.location_mocked"
                                        variant="destructive"
                                        class="text-[0.65rem]"
                                        >Simulada</Badge
                                    >
                                </span>
                            </template>
                            <template #value-device="{ item }">
                                <span class="inline-flex items-center gap-1.5">
                                    <Monitor
                                        class="size-3.5 text-muted-foreground"
                                        aria-hidden="true"
                                    />{{ item.value ?? '—' }}
                                </span>
                            </template>
                        </KeyValueGrid>
                        <p class="mt-3 text-xs text-muted-foreground">
                            Recibido
                            <DateTimeDisplay
                                :value="p.created_at"
                                :timezone="tz"
                                format="datetime-long"
                            />
                        </p>
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </div>
        <EmptyState
            v-else
            :icon="Fingerprint"
            size="sm"
            bordered
            title="Sin marcajes este día"
        />
    </SectionCard>

    <!-- Rectificaciones -->
    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <SectionCard title="Ajustes por rectificación" :icon="FilePenLine">
            <Timeline
                :items="adjustmentItems"
                date-format="time"
                empty-text="Sin ajustes aprobados."
            />
        </SectionCard>
        <SectionCard
            title="Solicitudes del día"
            :icon="CalendarRange"
            padding="none"
        >
            <ul v-if="rectifications.length > 0" class="divide-y">
                <li v-for="r in rectifications" :key="r.id">
                    <Link
                        :href="rectificaciones.show.url(r.id)"
                        class="flex items-start gap-3 px-5 py-3 transition-colors hover:bg-surface-2"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                {{ r.kind_label }}
                            </p>
                            <p
                                class="line-clamp-2 text-xs text-muted-foreground"
                            >
                                {{ r.reason }}
                            </p>
                            <p class="mt-1 text-xs text-subtle-foreground">
                                {{ r.requested_by }} ·
                                <DateTimeDisplay
                                    :value="r.created_at"
                                    format="relative"
                                />
                            </p>
                        </div>
                        <StatusBadge
                            :label="r.status_label"
                            :tone="approvalTone(r.status)"
                            size="sm"
                        />
                    </Link>
                </li>
            </ul>
            <div v-else class="p-5">
                <EmptyState
                    :icon="FilePenLine"
                    size="sm"
                    title="Sin solicitudes"
                />
            </div>
        </SectionCard>
    </div>

    <div
        v-if="summary"
        class="mt-6 flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
    >
        <UserAvatar :name="employee?.name ?? '—'" size="xs" />
        <span
            >Calculado
            <DateTimeDisplay
                :value="summary.computed_at"
                :timezone="tz"
                format="datetime"
        /></span>
        <span v-if="summary.legal_version_code"
            >· Normativa {{ summary.legal_version_code }}</span
        >
        <span>· {{ dayMonthShort(date) }} · {{ tz }}</span>
    </div>
</template>
