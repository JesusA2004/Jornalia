<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ArchiveRestore,
    CalendarClock,
    CalendarX2,
    CircleCheck,
    Clock3,
    EllipsisVertical,
    Moon,
    Pencil,
    Plus,
    Power,
    Scale,
    ShieldAlert,
    TriangleAlert,
    Users,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ScheduleAssignmentController from '@/actions/App/Http/Controllers/Web/Admin/ScheduleAssignmentController';
import ScheduleController from '@/actions/App/Http/Controllers/Web/Admin/ScheduleController';
import ScheduleExceptionController from '@/actions/App/Http/Controllers/Web/Admin/ScheduleExceptionController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SearchInput from '@/components/app/SearchInput.vue';
import SelectField from '@/components/app/SelectField.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Progress } from '@/components/ui/progress';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useFilters } from '@/composables/useFilters';
import { formatDate } from '@/lib/format';
import type { HelpStep } from '@/types';
import WeekStrip from './components/WeekStrip.vue';
import { hoursLabel, SHIFT_KIND_LABELS } from './types';
import type { LegalSummary, ScheduleData, ScheduleTypeOption } from './types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personal' },
            { title: 'Horarios', href: '/horarios' },
        ],
    },
});

const props = defineProps<{
    schedules: ScheduleData[];
    filters: {
        search?: string | null;
        type?: string | null;
        status?: string | null;
    };
    legal: LegalSummary;
    types: ScheduleTypeOption[];
    can: { manage: boolean; assign: boolean };
}>();

const { filters, activeCount, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        type: props.filters.type ?? null,
        status: props.filters.status ?? null,
    },
    { only: ['schedules', 'filters'], defaults: { search: '' } },
);

const statusTab = computed({
    get: () => (filters.status === 'inactive' ? 'inactive' : 'active'),
    set: (value: string) => {
        filters.status = value === 'inactive' ? 'inactive' : null;
    },
});

function onStatusTab(value: unknown): void {
    if (typeof value === 'string' && value) {
        statusTab.value = value;
    }
}

const typeLabel = (value: string) =>
    props.types.find((t) => t.value === value)?.label ?? value;

const help: HelpStep[] = [
    {
        title: 'Crea el horario',
        text: 'Define segmentos por día o por ciclo; los turnos pueden cruzar medianoche.',
        icon: Clock3,
    },
    {
        title: 'Validación legal',
        text: 'Se bloquea si rebasa la jornada máxima vigente hoy o en la fecha de vigencia.',
        icon: Scale,
    },
    {
        title: 'Reducción gradual',
        text: 'Los avisos indican desde qué fecha un horario dejará de cumplir.',
        icon: CalendarClock,
    },
    {
        title: 'Asigna',
        text: 'Individual desde el expediente o masiva por sucursal, departamento o selección.',
        icon: UsersRound,
    },
    {
        title: 'Excepciones',
        text: 'Cambios puntuales por colaborador y día (descanso o jornada especial).',
        icon: CalendarX2,
    },
];

const pendingAction = ref<{
    schedule: ScheduleData;
    kind: 'deactivate' | 'restore';
} | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function ask(schedule: ScheduleData, kind: 'deactivate' | 'restore'): void {
    pendingAction.value = { schedule, kind };
    confirmOpen.value = true;
}

function confirmAction(): void {
    if (!pendingAction.value) {
        return;
    }

    const { schedule, kind } = pendingAction.value;
    const action =
        kind === 'deactivate'
            ? ScheduleController.deactivate(schedule.id)
            : ScheduleController.restore(schedule.id);

    router.visit(action, {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
        },
    });
}

function usage(schedule: ScheduleData): number {
    return Math.min(
        100,
        Math.round(
            (schedule.weekly_minutes / Math.max(1, props.legal.weekly_max)) *
                100,
        ),
    );
}

function futureIssues(schedule: ScheduleData) {
    return schedule.checks.filter((c) => !c.ok && c.date > props.legal.today);
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            title="Horarios"
            description="Turnos, ciclos y jornadas validadas contra la ley vigente."
            :icon="Clock3"
            :help="help"
            help-title="Horarios y turnos"
        >
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.assign" variant="outline" as-child>
                        <Link :href="ScheduleExceptionController.index()">
                            <CalendarX2 aria-hidden="true" />
                            Excepciones
                        </Link>
                    </Button>
                    <Button v-if="can.assign" variant="outline" as-child>
                        <Link :href="ScheduleAssignmentController.index()">
                            <Users aria-hidden="true" />
                            Asignación masiva
                        </Link>
                    </Button>
                    <Button v-if="can.manage" as-child>
                        <Link :href="ScheduleController.create()">
                            <Plus aria-hidden="true" />
                            Nuevo horario
                        </Link>
                    </Button>
                </div>
            </template>
        </PageHeader>

        <!-- Marco legal vigente -->
        <section
            class="relative overflow-hidden rounded-2xl border bg-card p-5 shadow-elev-1"
            aria-label="Jornada legal"
        >
            <div
                class="pointer-events-none absolute -top-16 -right-10 size-48 rounded-full bg-brand/10 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="relative flex flex-col gap-5 lg:flex-row lg:items-center"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex size-12 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                    >
                        <Scale class="icon-lg" aria-hidden="true" />
                    </div>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Jornada ordinaria máxima hoy
                        </p>
                        <p class="font-display numeric text-3xl font-bold">
                            {{ hoursLabel(legal.weekly_max) }}
                            <span
                                class="text-base font-medium text-muted-foreground"
                                >/ semana</span
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ legal.version }} · diurna
                            {{ hoursLabel(legal.daily_max.day) }} · nocturna
                            {{ hoursLabel(legal.daily_max.night) }} · mixta
                            {{ hoursLabel(legal.daily_max.mixed) }}
                        </p>
                    </div>
                </div>
                <ol
                    v-if="legal.upcoming.length > 0"
                    class="flex flex-1 flex-wrap items-center gap-2 lg:justify-end"
                    aria-label="Próximas reducciones"
                >
                    <li
                        v-for="step in legal.upcoming"
                        :key="step.date"
                        class="flex items-center gap-2 rounded-xl border bg-surface-2 px-3 py-2"
                    >
                        <CalendarClock
                            class="icon-sm text-brand-strong"
                            aria-hidden="true"
                        />
                        <div class="leading-tight">
                            <p class="numeric text-sm font-semibold">
                                {{ hoursLabel(step.weekly_max) }}
                            </p>
                            <p class="text-[0.6875rem] text-muted-foreground">
                                desde {{ formatDate(step.date, 'date') }}
                            </p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <FilterBar :active-count="activeCount" @reset="reset">
            <template #search>
                <SearchInput
                    v-model="filters.search"
                    placeholder="Buscar por nombre o clave"
                />
            </template>
            <SelectField
                v-model="filters.type"
                :options="types"
                placeholder="Todos los tipos"
                prefix="Tipo:"
                clearable
                class="w-52"
            />
            <template #actions>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    :model-value="statusTab"
                    aria-label="Estado"
                    @update:model-value="onStatusTab"
                >
                    <ToggleGroupItem value="active" class="px-3"
                        >Activos</ToggleGroupItem
                    >
                    <ToggleGroupItem value="inactive" class="px-3"
                        >Inactivos</ToggleGroupItem
                    >
                </ToggleGroup>
            </template>
        </FilterBar>

        <EmptyState
            v-if="schedules.length === 0"
            :icon="Clock3"
            tone="primary"
            bordered
            class="rounded-2xl"
            :title="
                filters.status === 'inactive'
                    ? 'Sin horarios inactivos'
                    : activeCount > 0
                      ? 'Sin coincidencias'
                      : 'Aún no hay horarios'
            "
            :description="
                filters.status === 'inactive'
                    ? 'Los horarios desactivados aparecerán aquí.'
                    : activeCount > 0
                      ? 'Prueba con otros filtros.'
                      : 'Crea el primero para asignarlo a tu personal.'
            "
        >
            <template #actions>
                <Button v-if="can.manage && activeCount === 0" as-child>
                    <Link :href="ScheduleController.create()">
                        <Plus aria-hidden="true" />
                        Crear horario
                    </Link>
                </Button>
            </template>
        </EmptyState>

        <div v-else class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            <article
                v-for="schedule in schedules"
                :key="schedule.id"
                :class="[
                    'group flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-elev-1 transition-standard hover:shadow-elev-2',
                    !schedule.active && 'opacity-75',
                ]"
            >
                <header class="flex items-start gap-3">
                    <div
                        :class="[
                            'flex size-10 shrink-0 items-center justify-center rounded-xl',
                            schedule.shift_kind === 'night'
                                ? 'bg-info-soft text-info-soft-foreground'
                                : 'bg-brand-soft text-brand-soft-foreground',
                        ]"
                    >
                        <Moon
                            v-if="schedule.shift_kind === 'night'"
                            class="icon-md"
                            aria-hidden="true"
                        />
                        <Clock3 v-else class="icon-md" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-display text-base font-bold">
                            <Link
                                v-if="can.manage && schedule.active"
                                :href="ScheduleController.edit(schedule.id)"
                                class="hover:underline"
                                >{{ schedule.name }}</Link
                            >
                            <span v-else>{{ schedule.name }}</span>
                        </h2>
                        <p
                            class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                        >
                            <span class="font-mono">{{ schedule.code }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ typeLabel(schedule.type) }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{
                                SHIFT_KIND_LABELS[schedule.shift_kind]
                            }}</span>
                            <template v-if="schedule.cycle_length_days !== 7">
                                <span aria-hidden="true">·</span>
                                <span
                                    >ciclo
                                    {{ schedule.cycle_length_days }} d</span
                                >
                            </template>
                        </p>
                    </div>
                    <DropdownMenu v-if="can.manage">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Acciones"
                            >
                                <EllipsisVertical aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <template v-if="schedule.active">
                                <DropdownMenuItem as-child>
                                    <Link
                                        :href="
                                            ScheduleController.edit(schedule.id)
                                        "
                                    >
                                        <Pencil aria-hidden="true" />
                                        Editar
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    @select="ask(schedule, 'deactivate')"
                                >
                                    <Power aria-hidden="true" />
                                    Desactivar
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                v-else
                                @select="ask(schedule, 'restore')"
                            >
                                <ArchiveRestore aria-hidden="true" />
                                Reactivar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </header>

                <WeekStrip
                    :segments="schedule.segments"
                    :cycle="schedule.cycle_length_days"
                    :max-days="7"
                />

                <div class="space-y-1.5">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="numeric font-semibold">{{
                            hoursLabel(schedule.weekly_minutes)
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >de
                            {{ hoursLabel(legal.weekly_max) }} semanales</span
                        >
                    </div>
                    <Progress
                        :model-value="usage(schedule)"
                        :class="
                            schedule.compliant_today ? '' : '[&>*]:bg-danger'
                        "
                        aria-label="Uso de la jornada máxima"
                    />
                </div>

                <ul
                    v-if="
                        !schedule.compliant_today ||
                        futureIssues(schedule).length > 0
                    "
                    class="space-y-1.5"
                >
                    <li
                        v-if="!schedule.compliant_today"
                        class="flex items-start gap-2 rounded-lg bg-danger-soft px-3 py-2 text-xs text-danger-soft-foreground"
                    >
                        <ShieldAlert
                            class="mt-0.5 icon-xs shrink-0"
                            aria-hidden="true"
                        />
                        Rebasa la jornada legal vigente.
                    </li>
                    <li
                        v-for="issue in futureIssues(schedule)"
                        :key="issue.date"
                        class="flex items-start gap-2 rounded-lg bg-warning-soft px-3 py-2 text-xs text-warning-soft-foreground"
                    >
                        <TriangleAlert
                            class="mt-0.5 icon-xs shrink-0"
                            aria-hidden="true"
                        />
                        Desde {{ formatDate(issue.date, 'date') }} el máximo
                        será {{ hoursLabel(issue.weekly_max) }}.
                    </li>
                </ul>

                <footer
                    class="mt-auto flex items-center justify-between border-t pt-3 text-xs"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-muted-foreground"
                    >
                        <Users class="icon-xs" aria-hidden="true" />
                        <span class="numeric font-semibold text-foreground">{{
                            schedule.assigned ?? 0
                        }}</span>
                        asignados
                    </span>
                    <StatusBadge
                        v-if="!schedule.active"
                        status="inactive"
                        size="sm"
                    />
                    <StatusBadge
                        v-else-if="schedule.compliant_today"
                        status="compliant"
                        size="sm"
                    />
                    <StatusBadge v-else status="non_compliant" size="sm" />
                </footer>
            </article>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="
                pendingAction?.kind === 'deactivate'
                    ? '¿Desactivar horario?'
                    : '¿Reactivar horario?'
            "
            :description="
                pendingAction?.kind === 'deactivate'
                    ? 'Dejará de estar disponible para nuevas asignaciones. El historial se conserva.'
                    : 'Volverá a estar disponible para asignarse.'
            "
            :confirm-label="
                pendingAction?.kind === 'deactivate'
                    ? 'Desactivar'
                    : 'Reactivar'
            "
            :tone="pendingAction?.kind === 'deactivate' ? 'danger' : 'default'"
            :icon="pendingAction?.kind === 'deactivate' ? Power : CircleCheck"
            :loading="processing"
            :close-on-confirm="false"
            @confirm="confirmAction"
        />
    </div>
</template>
