<script setup lang="ts">
import {
    CalendarClock,
    CircleCheck,
    Fingerprint,
    MapPin,
    PenLine,
    Sparkles,
} from '@lucide/vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import MinutesDisplay from '@/components/app/MinutesDisplay.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { cn } from '@/lib/utils';
import type { DayDetail } from '@/types/operacion';
import { shortHash } from './shared';

/**
 * Día laboral en tres capas: horario programado, marcajes ORIGINALES
 * (inmutables), RECTIFICACIONES aprobadas y el HORARIO RECONOCIDO resultante.
 */
const props = defineProps<{ day: DayDetail; highlightUuid?: string | null }>();

const tz = () => props.day.timezone;
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Originales -->
        <section class="rounded-xl border bg-surface-1 p-4">
            <header class="mb-3 flex items-center gap-2">
                <span
                    class="flex size-7 items-center justify-center rounded-lg bg-neutral-soft text-neutral-soft-foreground"
                >
                    <Fingerprint class="size-4" aria-hidden="true" />
                </span>
                <h3 class="text-sm font-semibold">Marcajes originales</h3>
                <span class="ml-auto text-xs text-muted-foreground"
                    >Inmutables</span
                >
            </header>
            <p
                v-if="day.punches.length === 0"
                class="py-4 text-center text-sm text-muted-foreground"
            >
                Sin marcajes.
            </p>
            <ul v-else class="grid gap-2">
                <li
                    v-for="p in day.punches"
                    :key="p.uuid"
                    :class="
                        cn(
                            'rounded-lg border bg-card p-2.5 text-sm',
                            p.uuid === highlightUuid && 'ring-2 ring-brand/50',
                            p.voided && 'opacity-60',
                        )
                    "
                >
                    <div class="flex items-center justify-between gap-2">
                        <span
                            :class="
                                cn('font-medium', p.voided && 'line-through')
                            "
                            >{{ p.type_label }}</span
                        >
                        <DateTimeDisplay
                            :value="p.server_time"
                            :timezone="tz()"
                            format="time-seconds"
                            class="numeric font-semibold"
                        />
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <StatusBadge
                            :status="p.status"
                            :label="p.status_label"
                            size="sm"
                        />
                        <StatusBadge
                            v-if="p.voided"
                            label="Excluido del cálculo"
                            tone="neutral"
                            size="sm"
                            :dot="false"
                        />
                        <span class="text-xs text-muted-foreground"
                            >{{ p.source_label }} ·
                            {{ p.auth_method_label }}</span
                        >
                    </div>
                    <p
                        v-if="p.latitude"
                        class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <MapPin class="size-3" aria-hidden="true" />
                        {{ p.latitude }}, {{ p.longitude
                        }}<template v-if="p.accuracy_meters">
                            · ±{{ p.accuracy_meters }} m</template
                        >
                    </p>
                    <p
                        class="mt-1 font-mono text-[0.6875rem] text-subtle-foreground"
                    >
                        #{{ p.sequence }} · {{ shortHash(p.hash) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Rectificaciones -->
        <section class="rounded-xl border border-info/25 bg-info-soft/40 p-4">
            <header class="mb-3 flex items-center gap-2">
                <span
                    class="flex size-7 items-center justify-center rounded-lg bg-info-soft text-info-soft-foreground"
                >
                    <PenLine class="size-4" aria-hidden="true" />
                </span>
                <h3 class="text-sm font-semibold">Rectificaciones</h3>
                <span class="ml-auto text-xs text-muted-foreground"
                    >Ajustes aprobados</span
                >
            </header>
            <p
                v-if="day.adjustments.length === 0"
                class="py-4 text-center text-sm text-muted-foreground"
            >
                Sin ajustes.
            </p>
            <ul v-else class="grid gap-2">
                <li
                    v-for="a in day.adjustments"
                    :key="a.uuid"
                    class="rounded-lg border bg-card p-2.5 text-sm"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium"
                            >{{ a.action_label
                            }}<template v-if="a.type_label">
                                · {{ a.type_label }}</template
                            ></span
                        >
                        <DateTimeDisplay
                            v-if="a.recognized_time"
                            :value="a.recognized_time"
                            :timezone="tz()"
                            format="time"
                            class="numeric font-semibold"
                        />
                    </div>
                    <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">
                        {{ a.reason }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Aprobó {{ a.approved_by ?? '—' }}
                    </p>
                    <p
                        class="mt-1 font-mono text-[0.6875rem] text-subtle-foreground"
                    >
                        #{{ a.sequence }} · {{ shortHash(a.hash) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Reconocido -->
        <section
            class="rounded-xl border border-success/25 bg-success-soft/40 p-4"
        >
            <header class="mb-3 flex items-center gap-2">
                <span
                    class="flex size-7 items-center justify-center rounded-lg bg-success-soft text-success-soft-foreground"
                >
                    <CircleCheck class="size-4" aria-hidden="true" />
                </span>
                <h3 class="text-sm font-semibold">Horario reconocido</h3>
            </header>
            <div
                v-if="day.shift"
                class="mb-3 rounded-lg border border-dashed bg-card/70 p-2.5 text-xs"
            >
                <p class="flex items-center gap-1.5 font-medium">
                    <CalendarClock class="size-3.5" aria-hidden="true" />
                    {{ day.shift.schedule_name }}
                </p>
                <p class="mt-0.5 text-muted-foreground">
                    <DateTimeDisplay
                        :value="day.shift.starts_at"
                        :timezone="tz()"
                        format="time"
                        :tooltip="false"
                    />
                    –
                    <DateTimeDisplay
                        :value="day.shift.ends_at"
                        :timezone="tz()"
                        format="time"
                        :tooltip="false"
                    />
                    ·
                    <MinutesDisplay
                        :minutes="day.shift.scheduled_minutes"
                        format="short"
                    />
                    · tolerancia {{ day.shift.tolerance_minutes }} min
                </p>
            </div>
            <p
                v-else
                class="mb-3 rounded-lg border border-dashed p-2.5 text-xs text-muted-foreground"
            >
                {{
                    day.holiday
                        ? `Festivo: ${day.holiday.name}`
                        : 'Sin turno programado'
                }}
            </p>
            <p
                v-if="day.recognized.length === 0"
                class="py-2 text-center text-sm text-muted-foreground"
            >
                Sin eventos reconocidos.
            </p>
            <ol v-else class="grid gap-1.5">
                <li
                    v-for="(e, i) in day.recognized"
                    :key="i"
                    class="flex items-center gap-2 rounded-lg bg-card px-2.5 py-2 text-sm"
                >
                    <component
                        :is="e.origin === 'original' ? Fingerprint : Sparkles"
                        :class="
                            cn(
                                'size-4',
                                e.origin === 'original'
                                    ? 'text-muted-foreground'
                                    : 'text-info',
                            )
                        "
                        aria-hidden="true"
                    />
                    <span class="font-medium">{{ e.type_label }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        e.origin_label
                    }}</span>
                    <DateTimeDisplay
                        :value="e.time"
                        :timezone="tz()"
                        format="time"
                        class="ml-auto numeric font-semibold"
                    />
                </li>
            </ol>
        </section>
    </div>
</template>
