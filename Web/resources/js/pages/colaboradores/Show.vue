<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    BriefcaseBusiness,
    CalendarClock,
    CalendarDays,
    Clock3,
    History,
    KeyRound,
    MapPin,
    MoreHorizontal,
    Palmtree,
    ScanFace,
    Smartphone,
    UserMinus,
    UserPen,
    UserRound,
    UserRoundPlus,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Web/Admin/EmployeeController';
import FaceEnrollmentController from '@/actions/App/Http/Controllers/Web/Biometrics/FaceEnrollmentController';
import EmptyState from '@/components/app/EmptyState.vue';
import KeyValueGrid from '@/components/app/KeyValueGrid.vue';
import KpiTile from '@/components/app/KpiTile.vue';
import MinutesDisplay from '@/components/app/MinutesDisplay.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SectionCard from '@/components/app/SectionCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import Timeline from '@/components/app/Timeline.vue';
import UserAvatar from '@/components/app/UserAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Progress } from '@/components/ui/progress';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { HelpStep, KeyValueItem, TimelineItem } from '@/types';
import AccountPanel from './components/AccountPanel.vue';
import EditEmployeeSheet from './components/EditEmployeeSheet.vue';
import IdentifiersCard from './components/IdentifiersCard.vue';
import MoveSheet from './components/MoveSheet.vue';
import RegistrationCard from './components/RegistrationCard.vue';
import RehireSheet from './components/RehireSheet.vue';
import TerminateDialog from './components/TerminateDialog.vue';
import {
    CONTRACT_LABELS,
    EMPLOYEE_STATUS,
    MODALITY_LABELS,
    SHIFT_LABELS,
    WEEKDAYS,
    spanMinutes,
    todayIso,
} from './lib';
import type {
    Assignment,
    AssignmentField,
    EmployeeShowProps,
    ScheduleSegment,
} from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Colaboradores', href: '/colaboradores' },
            { title: 'Expediente' },
        ],
    },
});

const props = defineProps<EmployeeShowProps>();

const tab = ref(props.flash_password ? 'cuenta' : 'resumen');
const editOpen = ref(false);
const moveOpen = ref(false);
const terminateOpen = ref(false);
const rehireOpen = ref(false);

const today = todayIso();

const activeEmployment = computed(
    () =>
        props.employments.find(
            (em) =>
                em.status !== 'terminated' &&
                (em.ends_on === null || em.ends_on >= today),
        ) ?? null,
);
const lastEmployment = computed(() => props.employments[0] ?? null);
const lastAssignment = computed<Assignment | null>(
    () => lastEmployment.value?.assignments[0] ?? null,
);

const canMove = computed(
    () => props.can.move && activeEmployment.value !== null,
);
const canTerminate = computed(
    () => props.can.terminate && activeEmployment.value !== null,
);
const canRehire = computed(
    () =>
        props.can.rehire &&
        activeEmployment.value === null &&
        props.employments.length > 0,
);

const status = computed(
    () =>
        EMPLOYEE_STATUS[props.registration.display_status] ??
        EMPLOYEE_STATUS.inactive,
);

const personal = computed<KeyValueItem[]>(() => [
    {
        key: 'number',
        label: 'Número de empleado',
        value: props.employee.employee_number,
        mono: true,
    },
    { key: 'email', label: 'Correo', value: props.employee.email },
    { key: 'phone', label: 'Teléfono', value: props.employee.phone },
    {
        key: 'birth',
        label: 'Nacimiento',
        value: props.employee.birth_date
            ? formatDate(props.employee.birth_date, 'date-long')
            : null,
    },
    ...(props.employee.external_id
        ? [
              {
                  key: 'external',
                  label: 'Vínculo People',
                  value: props.employee.external_id,
                  mono: true,
              },
          ]
        : []),
]);

const assignmentItems = (a: Assignment | null): KeyValueItem[] => [
    { key: 'branch', label: 'Sucursal', value: a?.branch },
    { key: 'center', label: 'Centro de trabajo', value: a?.work_center },
    { key: 'department', label: 'Departamento', value: a?.department },
    { key: 'position', label: 'Puesto', value: a?.position },
    { key: 'schedule', label: 'Horario', value: a?.schedule },
    { key: 'supervisor', label: 'Supervisor', value: a?.supervisor },
    {
        key: 'policy',
        label: 'Política de asistencia',
        value: a?.attendance_policy ?? 'Por defecto',
    },
    {
        key: 'since',
        label: 'Vigente desde',
        value: a ? formatDate(a.effective_from, 'date-long') : null,
    },
];

const employmentItems = computed<KeyValueItem[]>(() => {
    const em = activeEmployment.value ?? lastEmployment.value;

    return [
        {
            key: 'starts',
            label: 'Ingreso',
            value: em ? formatDate(em.starts_on, 'date-long') : null,
        },
        {
            key: 'seniority',
            label: 'Antigüedad desde',
            value: em ? formatDate(em.seniority_starts_on, 'date-long') : null,
        },
        {
            key: 'contract',
            label: 'Contrato',
            value: em?.contract_type ? CONTRACT_LABELS[em.contract_type] : null,
        },
        {
            key: 'modality',
            label: 'Modalidad',
            value: em?.work_modality ? MODALITY_LABELS[em.work_modality] : null,
        },
        {
            key: 'entity',
            label: 'Razón social',
            value: em?.legal_entity,
            full: true,
        },
    ];
});

function range(from: string, to: string | null): string {
    return `${formatDate(from, 'date-short')} → ${to ? formatDate(to, 'date-short') : 'vigente'}`;
}

function assignmentTimeline(assignments: Assignment[]): TimelineItem[] {
    return assignments.map((a) => {
        const isCurrent =
            a.effective_from <= today &&
            (a.effective_to === null || a.effective_to >= today);
        const isFuture = a.effective_from > today;
        const parts = [
            a.branch,
            a.position,
            a.schedule ? `Horario ${a.schedule}` : null,
        ].filter(Boolean);

        return {
            id: a.id,
            title: range(a.effective_from, a.effective_to),
            description: parts.join(' · ') || 'Sin asignación',
            tone: isCurrent ? 'success' : isFuture ? 'info' : 'neutral',
            icon: isFuture ? CalendarClock : MapPin,
            actor: a.created_by,
            meta: a.reason,
        };
    });
}

const historyItems = computed<TimelineItem[]>(() =>
    props.history.map((h) => ({
        id: h.id,
        title: h.description,
        at: h.at,
        actor: h.actor,
        tone: h.action.includes('terminated')
            ? 'danger'
            : h.action.includes('hired') || h.action.includes('rehired')
              ? 'success'
              : h.action.includes('moved')
                ? 'info'
                : 'neutral',
        icon: h.action.includes('moved')
            ? ArrowRightLeft
            : h.action.includes('account')
              ? KeyRound
              : History,
    })),
);

const timeOf = (iso: string | null) =>
    iso
        ? formatDateTime(iso, { format: 'time', timeZone: props.timezone })
        : '';

const segmentsByDay = computed(() => {
    if (!props.schedule) {
        return [];
    }

    const days: {
        index: number;
        label: string;
        segments: ScheduleSegment[];
    }[] = [];

    for (let i = 0; i < props.schedule.cycle_length_days; i++) {
        days.push({
            index: i,
            label:
                props.schedule.cycle_length_days === 7
                    ? WEEKDAYS[i]
                    : `Día ${i + 1}`,
            segments: props.schedule.segments.filter((s) => s.day_index === i),
        });
    }

    return days;
});

const vacationUsage = computed(() => {
    const v = props.vacations;

    return v && v.entitled_days > 0
        ? Math.round(((v.used_days + v.pending_days) / v.entitled_days) * 100)
        : 0;
});

const help: HelpStep[] = [
    {
        title: 'Movimientos con vigencia',
        text: 'Cada cambio cierra la vigencia actual el día anterior; nunca se modifican fechas pasadas.',
        icon: ArrowRightLeft,
    },
    {
        title: 'Baja',
        text: 'Cierra la relación laboral y desactiva el acceso. Nada se borra.',
        icon: UserMinus,
    },
    {
        title: 'Reingreso',
        text: 'Abre una relación laboral nueva y conserva la anterior en el historial.',
        icon: UserRoundPlus,
    },
    {
        title: 'Cuenta de acceso',
        text: 'Envía un enlace o genera una contraseña temporal que se muestra una sola vez.',
        icon: KeyRound,
    },
];

const movePreset = ref<AssignmentField | null>(null);

watch(moveOpen, (isOpen) => {
    if (!isOpen) {
        movePreset.value = null;
    }
});
</script>

<template>
    <PageHeader
        :title="employee.full_name"
        :back-href="EmployeeController.index.url()"
        back-label="Colaboradores"
        :help="help"
        help-title="Expediente"
    >
        <template #meta>
            <div
                class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
            >
                <StatusBadge
                    :status="registration.display_status"
                    :label="status.label"
                    :tone="status.tone"
                />
                <span class="font-mono text-xs"
                    >#{{ employee.employee_number }}</span
                >
                <template v-if="current?.position">
                    <span aria-hidden="true">·</span>
                    <span>{{ current.position }}</span>
                </template>
                <template v-if="current?.branch">
                    <span aria-hidden="true">·</span>
                    <span class="inline-flex items-center gap-1"
                        ><MapPin class="size-3.5" aria-hidden="true" />{{
                            current.branch
                        }}</span
                    >
                </template>
            </div>
        </template>
        <template #actions>
            <Button v-if="can.update" variant="outline" as-child>
                <Link :href="FaceEnrollmentController.show.url(employee.id)">
                    <ScanFace aria-hidden="true" />
                    Rostro
                    <Badge
                        :variant="face.enrolled ? 'default' : 'outline'"
                        class="px-1.5 text-[0.625rem]"
                    >
                        {{ face.enrolled ? 'Registrado' : 'No registrado' }}
                    </Badge>
                </Link>
            </Button>
            <Button
                v-if="can.update"
                variant="outline"
                @click="editOpen = true"
            >
                <UserPen aria-hidden="true" />
                Editar
            </Button>
            <Button v-if="canMove" @click="moveOpen = true">
                <ArrowRightLeft aria-hidden="true" />
                Movimiento
            </Button>
            <Button v-if="canRehire" @click="rehireOpen = true">
                <UserRoundPlus aria-hidden="true" />
                Reingreso
            </Button>
            <DropdownMenu v-if="canTerminate">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Más acciones"
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        class="text-danger-soft-foreground"
                        @select="terminateOpen = true"
                    >
                        <UserMinus aria-hidden="true" />
                        Dar de baja
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
    </PageHeader>

    <div
        v-if="!activeEmployment && employments.length"
        class="flex items-center gap-3 rounded-2xl border border-dashed bg-surface-2 px-5 py-4 text-sm"
    >
        <UserMinus class="size-5 text-muted-foreground" aria-hidden="true" />
        <span>
            Baja desde el
            <strong>{{
                lastEmployment?.ends_on
                    ? formatDate(lastEmployment.ends_on, 'date-long')
                    : '—'
            }}</strong>
            <template v-if="lastEmployment?.termination_reason">
                · {{ lastEmployment.termination_reason }}</template
            >
        </span>
    </div>

    <Tabs v-model="tab" class="gap-6">
        <TabsList class="w-full justify-start overflow-x-auto">
            <TabsTrigger value="resumen"
                ><UserRound aria-hidden="true" />Resumen</TabsTrigger
            >
            <TabsTrigger value="laboral"
                ><BriefcaseBusiness aria-hidden="true" />Relación
                laboral</TabsTrigger
            >
            <TabsTrigger value="horario"
                ><Clock3 aria-hidden="true" />Horario</TabsTrigger
            >
            <TabsTrigger value="dispositivos">
                <Smartphone aria-hidden="true" />Dispositivos
                <Badge v-if="devices.length" variant="secondary" class="ml-1">{{
                    devices.length
                }}</Badge>
            </TabsTrigger>
            <TabsTrigger value="vacaciones"
                ><Palmtree aria-hidden="true" />Vacaciones</TabsTrigger
            >
            <TabsTrigger value="cuenta"
                ><KeyRound aria-hidden="true" />Cuenta</TabsTrigger
            >
            <TabsTrigger value="historial"
                ><History aria-hidden="true" />Historial</TabsTrigger
            >
        </TabsList>

        <!-- Resumen -->
        <TabsContent
            value="resumen"
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <div class="grid gap-6">
                <RegistrationCard
                    :employee-id="employee.id"
                    :registration="registration"
                    :can-upload="can.update"
                />
                <SectionCard title="Asignación vigente" :icon="MapPin" divided>
                    <template v-if="canMove" #actions>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="moveOpen = true"
                        >
                            <ArrowRightLeft aria-hidden="true" />
                            Cambiar
                        </Button>
                    </template>
                    <KeyValueGrid
                        v-if="current"
                        :items="assignmentItems(current)"
                    />
                    <EmptyState
                        v-else
                        size="sm"
                        :icon="MapPin"
                        title="Sin asignación"
                    />
                </SectionCard>
                <SectionCard
                    title="Relación laboral"
                    :icon="BriefcaseBusiness"
                    divided
                >
                    <KeyValueGrid :items="employmentItems" />
                </SectionCard>
                <IdentifiersCard
                    v-if="can.sensitive"
                    :employee-id="employee.id"
                    :has="employee.has_identifiers"
                    :can-reveal="can.sensitive"
                />
            </div>
            <div class="grid h-fit gap-6">
                <SectionCard padding="md">
                    <UserAvatar
                        :name="employee.full_name"
                        :subtitle="employee.email"
                        size="xl"
                        show-name
                    />
                    <KeyValueGrid :items="personal" :columns="1" class="mt-5" />
                </SectionCard>
                <KpiTile
                    label="Vacaciones disponibles"
                    :value="vacations?.available_days ?? null"
                    unit="días"
                    :icon="Palmtree"
                    tone="brand"
                    :hint="
                        vacations
                            ? `Año de servicio ${vacations.service_year}`
                            : (vacations_error ?? undefined)
                    "
                />
            </div>
        </TabsContent>

        <!-- Relación laboral -->
        <TabsContent value="laboral" class="grid gap-6">
            <EmptyState
                v-if="!employments.length"
                :icon="BriefcaseBusiness"
                bordered
                title="Sin relación laboral"
            />
            <SectionCard
                v-for="em in employments"
                :key="em.id"
                :class="cn(em === activeEmployment && 'ring-1 ring-brand/30')"
                divided
            >
                <template #header>
                    <div class="flex flex-wrap items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand-soft-foreground"
                        >
                            <BriefcaseBusiness
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <div>
                            <p class="font-display text-lg font-semibold">
                                {{ range(em.starts_on, em.ends_on) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    em.contract_type
                                        ? CONTRACT_LABELS[em.contract_type]
                                        : '—'
                                }}
                                ·
                                {{
                                    em.work_modality
                                        ? MODALITY_LABELS[em.work_modality]
                                        : '—'
                                }}
                                <template v-if="em.legal_entity">
                                    · {{ em.legal_entity }}</template
                                >
                            </p>
                        </div>
                        <StatusBadge
                            class="ml-auto"
                            :status="
                                em === activeEmployment
                                    ? 'active'
                                    : 'terminated'
                            "
                            :label="
                                em === activeEmployment
                                    ? 'Vigente'
                                    : 'Concluida'
                            "
                            size="sm"
                        />
                    </div>
                </template>
                <p
                    v-if="em.termination_reason"
                    class="mb-4 rounded-lg bg-surface-2 px-3 py-2 text-sm"
                >
                    <span class="font-semibold">Motivo de baja:</span>
                    {{ em.termination_reason }}
                </p>
                <Timeline :items="assignmentTimeline(em.assignments)" />
            </SectionCard>
        </TabsContent>

        <!-- Horario -->
        <TabsContent value="horario" class="grid gap-6">
            <SectionCard title="Próximos 7 días" :icon="CalendarDays" divided>
                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7"
                >
                    <div
                        v-for="day in week"
                        :key="day.date"
                        :class="
                            cn(
                                'rounded-xl border p-3',
                                day.date === today &&
                                    'border-brand/40 bg-brand-soft',
                                !day.starts_at && 'bg-surface-2',
                            )
                        "
                    >
                        <p
                            class="text-xs font-semibold text-muted-foreground capitalize"
                        >
                            {{ formatDate(day.date, 'weekday') }}
                        </p>
                        <p class="text-sm font-bold">
                            {{ formatDate(day.date, 'date-short') }}
                        </p>
                        <div class="mt-2 min-h-10 text-sm">
                            <template v-if="day.holiday">
                                <Badge variant="secondary">{{
                                    day.holiday
                                }}</Badge>
                            </template>
                            <template v-else-if="day.starts_at">
                                <p class="numeric font-semibold">
                                    {{ timeOf(day.starts_at) }}–{{
                                        timeOf(day.ends_at)
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ day.kind ? SHIFT_LABELS[day.kind] : '' }}
                                    <template v-if="day.from_exception">
                                        · excepción</template
                                    >
                                </p>
                            </template>
                            <p v-else class="text-xs text-muted-foreground">
                                Descanso
                            </p>
                        </div>
                    </div>
                </div>
            </SectionCard>

            <SectionCard
                v-if="schedule"
                :title="schedule.name"
                :description="`Clave ${schedule.code}`"
                :icon="Clock3"
                divided
            >
                <template #actions>
                    <Badge variant="outline">{{
                        SHIFT_LABELS[schedule.shift_kind]
                    }}</Badge>
                    <Badge variant="secondary"
                        ><MinutesDisplay
                            :minutes="schedule.weekly_minutes"
                            format="short"
                        />
                        / semana</Badge
                    >
                </template>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="day in segmentsByDay"
                        :key="day.index"
                        class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2"
                    >
                        <span class="text-sm font-medium">{{ day.label }}</span>
                        <span
                            v-if="day.segments.length"
                            class="text-right numeric text-sm"
                        >
                            <span
                                v-for="s in day.segments"
                                :key="s.ordinal"
                                class="block"
                            >
                                {{ s.starts_at }}–{{ s.ends_at }}
                                <span class="text-xs text-muted-foreground"
                                    >({{
                                        Math.round(
                                            (spanMinutes(
                                                s.starts_at,
                                                s.ends_at,
                                            ) -
                                                s.break_minutes) /
                                                6,
                                        ) / 10
                                    }}
                                    h)</span
                                >
                            </span>
                        </span>
                        <span v-else class="text-xs text-muted-foreground"
                            >Descanso</span
                        >
                    </div>
                </div>
                <p class="mt-4 text-xs text-muted-foreground">
                    Tolerancia de {{ schedule.tolerance_minutes }} min.
                </p>
            </SectionCard>
            <EmptyState
                v-else
                :icon="Clock3"
                bordered
                class="rounded-2xl"
                title="Sin horario asignado"
            >
                <template v-if="canMove" #actions>
                    <Button variant="outline" @click="moveOpen = true"
                        >Asignar horario</Button
                    >
                </template>
            </EmptyState>

            <SectionCard
                title="Cambios de horario programados"
                :icon="CalendarClock"
                divided
                padding="none"
            >
                <template v-if="can.schedule" #actions>
                    <Button variant="ghost" size="sm" as-child>
                        <Link
                            :href="`/horarios/cambios?employee_id=${employee.id}`"
                            >Administrar</Link
                        >
                    </Button>
                </template>
                <ul v-if="exceptions.length" class="divide-y">
                    <li
                        v-for="x in exceptions"
                        :key="x.id"
                        class="flex items-center justify-between gap-4 px-5 py-3 text-sm"
                    >
                        <div>
                            <p class="font-medium">
                                {{ formatDate(x.date, 'date-long') }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ x.reason }}
                                <template
                                    v-if="x.status && x.status !== 'approved'"
                                >
                                    ·
                                    {{
                                        x.status === 'pending'
                                            ? 'Por autorizar'
                                            : 'Rechazado'
                                    }}
                                </template>
                            </p>
                        </div>
                        <Badge v-if="x.is_rest_day" variant="secondary"
                            >Descanso</Badge
                        >
                        <span v-else class="numeric font-semibold"
                            >{{ x.starts_at }}–{{ x.ends_at }}</span
                        >
                    </li>
                </ul>
                <EmptyState
                    v-else
                    size="sm"
                    :icon="CalendarClock"
                    title="Sin cambios programados"
                />
            </SectionCard>
        </TabsContent>

        <!-- Dispositivos -->
        <TabsContent value="dispositivos">
            <div
                v-if="devices.length"
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <SectionCard v-for="d in devices" :key="d.id" padding="md">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-11 items-center justify-center rounded-xl bg-surface-3"
                        >
                            <Smartphone class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ d.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ d.platform === 'ios' ? 'iOS' : 'Android'
                                }}<template v-if="d.model">
                                    · {{ d.model }}</template
                                >
                                <template v-if="d.app_version">
                                    · v{{ d.app_version }}</template
                                >
                            </p>
                        </div>
                        <StatusBadge
                            :status="d.status"
                            :label="
                                {
                                    pending: 'Pendiente',
                                    approved: 'Autorizado',
                                    revoked: 'Revocado',
                                }[d.status]
                            "
                            size="sm"
                        />
                    </div>
                    <div
                        class="mt-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Badge v-if="d.is_corporate" variant="outline"
                            >Corporativo</Badge
                        >
                        <span
                            >Última conexión:
                            {{
                                d.last_seen_at
                                    ? formatDateTime(d.last_seen_at, {
                                          format: 'datetime',
                                      })
                                    : '—'
                            }}</span
                        >
                    </div>
                    <p
                        v-if="d.revoke_reason"
                        class="mt-2 text-xs text-danger-soft-foreground"
                    >
                        {{ d.revoke_reason }}
                    </p>
                </SectionCard>
            </div>
            <EmptyState
                v-else
                :icon="Smartphone"
                bordered
                class="rounded-2xl"
                title="Sin dispositivos"
                description="Aparecen cuando vincula la app."
            />
            <div
                v-if="can.devices && devices.length"
                class="mt-4 flex justify-end"
            >
                <Button variant="outline" as-child>
                    <Link href="/dispositivos">Ir a dispositivos</Link>
                </Button>
            </div>
        </TabsContent>

        <!-- Vacaciones -->
        <TabsContent value="vacaciones" class="grid gap-6">
            <template v-if="vacations">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <KpiTile
                        label="Disponibles"
                        :value="vacations.available_days"
                        unit="días"
                        :icon="Palmtree"
                        tone="brand"
                    />
                    <KpiTile
                        label="Derecho del periodo"
                        :value="vacations.entitled_days"
                        unit="días"
                        tone="primary"
                        :hint="`${vacations.legal_days} de ley + ${vacations.extra_days} adicionales`"
                    />
                    <KpiTile
                        label="Disfrutados"
                        :value="vacations.used_days"
                        unit="días"
                        tone="success"
                    />
                    <KpiTile
                        label="En trámite"
                        :value="vacations.pending_days"
                        unit="días"
                        tone="warning"
                    />
                </div>
                <SectionCard
                    title="Periodo vigente"
                    :icon="CalendarDays"
                    divided
                >
                    <div class="grid gap-4">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 text-sm"
                        >
                            <span
                                >Año de servicio
                                <strong>{{ vacations.service_year }}</strong> ·
                                {{
                                    range(
                                        vacations.period_starts_on,
                                        vacations.period_ends_on,
                                    )
                                }}</span
                            >
                            <span class="text-muted-foreground"
                                >Prima vacacional
                                {{ vacations.vacation_premium_percent }} %</span
                            >
                        </div>
                        <Progress :model-value="vacationUsage" />
                        <p class="text-xs text-muted-foreground">
                            {{ vacations.rule_source }}
                        </p>
                    </div>
                </SectionCard>
            </template>
            <EmptyState
                v-else
                :icon="Palmtree"
                bordered
                class="rounded-2xl"
                title="Sin saldo de vacaciones"
                :description="
                    vacations_error ?? 'No hay relación laboral vigente.'
                "
            />
        </TabsContent>

        <!-- Cuenta -->
        <TabsContent value="cuenta">
            <AccountPanel
                :employee-id="employee.id"
                :employee-email="employee.email"
                :account="account"
                :can-manage="can.account"
                :temporary-password="flash_password"
            />
        </TabsContent>

        <!-- Historial -->
        <TabsContent value="historial">
            <SectionCard
                title="Bitácora del expediente"
                :icon="History"
                divided
            >
                <Timeline
                    :items="historyItems"
                    empty-text="Sin cambios registrados."
                />
            </SectionCard>
        </TabsContent>
    </Tabs>

    <EditEmployeeSheet
        v-if="can.update"
        v-model:open="editOpen"
        :employee="employee"
        :can-sensitive="can.sensitive"
    />
    <MoveSheet
        v-if="canMove"
        v-model:open="moveOpen"
        :employee-id="employee.id"
        :current="current"
        :options="options"
        :schedule-only="!can.update"
        :preset="movePreset"
    />
    <TerminateDialog
        v-if="canTerminate"
        v-model:open="terminateOpen"
        :employee-id="employee.id"
        :employee-name="employee.full_name"
        :starts-on="activeEmployment?.starts_on ?? null"
    />
    <RehireSheet
        v-if="canRehire"
        v-model:open="rehireOpen"
        :employee-id="employee.id"
        :last="lastAssignment"
        :last-ends-on="lastEmployment?.ends_on ?? null"
        :options="options"
    />
</template>
