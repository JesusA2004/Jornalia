<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
} from '@lucide/vue';
import { computed } from 'vue';
import VacationController from '@/actions/App/Http/Controllers/Web/Operacion/VacationController';
import EmptyState from '@/components/app/EmptyState.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type {
    VacationCalendarDay,
    VacationIndexProps,
} from '@/types/operacion';
import { visitWith } from '../../rectificaciones/components/shared';

/** Calendario mensual de ausencias del equipo con conflictos de cobertura. */
const props = defineProps<{
    calendar: NonNullable<VacationIndexProps['calendar']>;
    today: string;
}>();

const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

const monthLabel = computed(() => {
    const d = new Date(`${props.calendar.month}-01T12:00:00Z`);
    const text = new Intl.DateTimeFormat('es-MX', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(d);

    return text.charAt(0).toUpperCase() + text.slice(1);
});

/** Celdas con huecos iniciales para alinear al lunes. */
const cells = computed<Array<VacationCalendarDay | null>>(() => {
    const first = new Date(`${props.calendar.start}T12:00:00Z`);
    const offset = (first.getUTCDay() + 6) % 7;

    return [
        ...Array.from({ length: offset }, () => null),
        ...props.calendar.days,
    ];
});

function shiftMonth(delta: number): void {
    const [y, m] = props.calendar.month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + delta, 1));
    visitWith({
        month: `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`,
    });
}

function conflictLevel(day: VacationCalendarDay): 'high' | 'medium' | null {
    if (day.conflicts.some((c) => c.level === 'high')) {
        return 'high';
    }

    return day.conflicts.length > 0 ? 'medium' : null;
}
</script>

<template>
    <div class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="icon-sm"
                    aria-label="Mes anterior"
                    @click="shiftMonth(-1)"
                >
                    <ChevronLeft aria-hidden="true" />
                </Button>
                <h3 class="min-w-40 text-center font-display text-lg font-bold">
                    {{ monthLabel }}
                </h3>
                <Button
                    variant="outline"
                    size="icon-sm"
                    aria-label="Mes siguiente"
                    @click="shiftMonth(1)"
                >
                    <ChevronRight aria-hidden="true" />
                </Button>
            </div>
            <div
                class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
            >
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-2.5 rounded-sm bg-success" />
                    Aprobada</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-sm border border-dashed border-warning bg-warning-soft"
                    />
                    Pendiente</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><AlertTriangle class="size-3.5 text-danger" /> Cobertura en
                    riesgo</span
                >
                <span class="numeric font-medium text-foreground"
                    >{{ calendar.total_requests }} solicitudes ·
                    {{ calendar.conflict_days }} días con conflicto</span
                >
            </div>
        </div>

        <EmptyState
            v-if="calendar.total_requests === 0"
            :icon="CalendarRange"
            bordered
            title="Mes sin ausencias"
            description="Nadie del equipo tiene vacaciones este mes."
        />

        <div v-else class="overflow-x-auto">
            <div
                class="grid min-w-[44rem] grid-cols-7 gap-px overflow-hidden rounded-xl border bg-border"
            >
                <div
                    v-for="w in WEEKDAYS"
                    :key="w"
                    class="bg-surface-2 px-2 py-2 text-center text-xs font-semibold text-muted-foreground uppercase"
                >
                    {{ w }}
                </div>
                <template
                    v-for="(day, index) in cells"
                    :key="day?.date ?? `empty-${index}`"
                >
                    <div v-if="!day" class="min-h-28 bg-surface-1/60" />
                    <div
                        v-else
                        :class="
                            cn(
                                'relative flex min-h-28 flex-col gap-1 bg-card p-1.5',
                                conflictLevel(day) === 'high' &&
                                    'bg-danger-soft/50',
                                conflictLevel(day) === 'medium' &&
                                    'bg-warning-soft/40',
                            )
                        "
                    >
                        <div class="flex items-center justify-between">
                            <span
                                :class="
                                    cn(
                                        'flex size-6 items-center justify-center rounded-full numeric text-xs font-semibold',
                                        day.date === today
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground',
                                    )
                                "
                                >{{ Number(day.date.slice(8, 10)) }}</span
                            >
                            <TooltipProvider v-if="day.conflicts.length > 0">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <button
                                            type="button"
                                            class="rounded p-0.5"
                                            aria-label="Conflicto de cobertura"
                                        >
                                            <AlertTriangle
                                                :class="
                                                    cn(
                                                        'size-4',
                                                        conflictLevel(day) ===
                                                            'high'
                                                            ? 'text-danger'
                                                            : 'text-warning',
                                                    )
                                                "
                                            />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent class="grid gap-1">
                                        <p
                                            v-for="c in day.conflicts"
                                            :key="c.branch"
                                            class="text-xs"
                                        >
                                            <strong>{{ c.branch }}</strong
                                            >: {{ c.absent }}/{{
                                                c.headcount
                                            }}
                                            ausentes ({{ c.ratio }} %)
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <Link
                            v-for="a in day.absences.slice(0, 3)"
                            :key="`${a.request_id}-${day.date}`"
                            :href="VacationController.show.url(a.request_id)"
                            :title="`${a.name}${a.branch ? ' · ' + a.branch : ''}`"
                            :class="
                                cn(
                                    'truncate rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium transition-opacity hover:opacity-80',
                                    a.status === 'approved'
                                        ? 'bg-success-soft text-success-soft-foreground'
                                        : 'border border-dashed border-warning/60 bg-warning-soft text-warning-soft-foreground',
                                )
                            "
                        >
                            {{ a.name }}
                        </Link>
                        <span
                            v-if="day.absences.length > 3"
                            class="px-1.5 text-[0.6875rem] text-muted-foreground"
                        >
                            +{{ day.absences.length - 3 }} más
                        </span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
