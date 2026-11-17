<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Ban,
    Bot,
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
    Sparkles,
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
import { formatMinutes } from '@/lib/format';
import { cn } from '@/lib/utils';
import DayRuler from '@/pages/asistencias/components/DayRuler.vue';
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
            label: 'Origen',
            value:
                p.source_label +
                (p.captured_offline ? ' · capturado sin conexión' : ''),
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
                ? 'Sistema (cierre automático)'
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
                ? `${p.clock_skew_seconds ?? 0} s de desfase`
                : null,
        },
        { key: 'ip', label: 'IP', value: p.ip_address, mono: true },
        {
            key: 'signature',
            label: 'Firma',
            value: p.is_system
                ? 'No aplica (sistema)'
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

const osm = (p: PunchDetail) =>
    `https://www.openstreetmap.org/?mlat=${p.latitude}&mlon=${p.longitude}#map=18/${p.latitude}/${p.longitude}`;

/** Salida automática por política (registrada por el sistema). */
const autoOut = computed(() => props.day.auto_clock_out);
const isAuto = (uuid: string | null) =>
    uuid !== null && uuid === autoOut.value?.punch_uuid;

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
        title: isAuto(e.punch_uuid)
            ? 'Salida automática por política'
            : e.type_label,
        description: isAuto(e.punch_uuid)
            ? 'Hora límite aplicada por el sistema'
            : e.origin_label,
        at: e.time,
        timezone: tz.value,
        tone: isAuto(e.punch_uuid)
            ? 'info'
            : e.origin === 'original'
              ? 'success'
              : 'info',
        icon: isAuto(e.punch_uuid)
            ? Bot
            : e.origin === 'original'
              ? CheckCircle2
              : Sparkles,
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
        text: 'Ajustes aprobados que agregan, reemplazan o excluyen un marcaje del cálculo, sin borrarlo.',
        icon: FilePenLine,
    },
    {
        title: 'Reconocido',
        text: 'La secuencia efectiva que se usa para calcular la jornada.',
        icon: CheckCircle2,
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
                    cn('h-1.5 w-6 rounded-full', {
                        'bg-success': workdayTone(d.status) === 'success',
                        'bg-warning': workdayTone(d.status) === 'warning',
                        'bg-danger': workdayTone(d.status) === 'danger',
                        'bg-info': workdayTone(d.status) === 'info',
                        'bg-brand': workdayTone(d.status) === 'brand',
                        'bg-border': ['neutral', 'primary'].includes(
                            workdayTone(d.status),
                        ),
                    })
                "
                :title="d.status_label"
            />
        </Link>
    </nav>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <KpiTile
            label="Estado"
            :value="summary?.status_label ?? 'Sin calcular'"
            :icon="CalendarClock"
            :tone="workdayTone(summary?.status)"
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
            label="Excedente"
            :value="formatMinutes(summary?.excess_minutes ?? 0)"
            :hint="
                summary?.after_cutoff_minutes
                    ? `Después del cierre (no reconocido) ${formatMinutes(summary.after_cutoff_minutes)}`
                    : summary?.night_minutes
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
                <span v-if="autoOut" class="flex items-center gap-1"
                    ><span
                        class="size-2.5 rounded-sm border-2 border-dashed border-info"
                    />Automática</span
                >
            </div>
        </template>
        <DayRuler :day="day" />
        <div
            v-if="autoOut"
            class="mt-4 flex flex-col gap-3 rounded-xl border border-dashed border-info/50 bg-info-soft/40 p-4 sm:flex-row sm:items-center"
        >
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-info-soft text-info-soft-foreground"
            >
                <Bot class="size-4" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-medium">
                    Salida automática por política (<DateTimeDisplay
                        :value="autoOut.cutoff_at"
                        :timezone="tz"
                        format="time"
                    />)
                </p>
                <p class="text-xs text-muted-foreground">
                    Registrada por el sistema a las
                    <DateTimeDisplay
                        :value="autoOut.registered_at"
                        :timezone="tz"
                        format="time"
                    />
                    <template v-if="autoOut.real_out_at">
                        · Salida real del colaborador
                        <DateTimeDisplay
                            :value="autoOut.real_out_at"
                            :timezone="tz"
                            format="time"
                        />
                    </template>
                </p>
            </div>
            <div
                v-if="
                    (summary?.after_cutoff_minutes ??
                        autoOut.after_cutoff_minutes) > 0
                "
                class="rounded-lg bg-card px-3 py-2 text-right ring-1 ring-border"
            >
                <p class="font-display numeric text-lg font-bold">
                    {{
                        formatMinutes(
                            summary?.after_cutoff_minutes ||
                                autoOut.after_cutoff_minutes,
                        )
                    }}
                </p>
                <p class="text-[0.7rem] text-muted-foreground">
                    después del cierre (no reconocidos)
                </p>
            </div>
        </div>
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
                            'border-dashed border-info/50 bg-info-soft/30',
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
                                    ? 'bg-info-soft text-info-soft-foreground'
                                    : p.type === 'clock_in'
                                      ? 'bg-success-soft text-success-soft-foreground'
                                      : p.type === 'clock_out'
                                        ? 'bg-danger-soft text-danger-soft-foreground'
                                        : 'bg-warning-soft text-warning-soft-foreground',
                            )
                        "
                    >
                        <Bot
                            v-if="p.is_system"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <Fingerprint v-else class="size-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            v-if="p.is_system"
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            Salida automática por política
                            <DateTimeDisplay
                                :value="p.recognized_time"
                                :timezone="tz"
                                format="time"
                                class="font-display numeric text-lg font-bold"
                            />
                        </p>
                        <p
                            v-else
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
                        <p
                            v-if="p.is_system"
                            class="text-xs text-muted-foreground"
                        >
                            Registrada por el sistema a las
                            <DateTimeDisplay
                                :value="p.server_time"
                                :timezone="tz"
                                format="time"
                            />
                            · {{ p.auth_method_label }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            {{ p.source_label }} · {{ p.auth_method_label }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
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
