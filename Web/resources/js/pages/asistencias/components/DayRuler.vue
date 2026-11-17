<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { DayDetail } from '@/types/operacion';

/**
 * Regla horaria del día (00–24 h, hora local del centro de trabajo) con tres
 * carriles: horario programado, marcajes originales y horario reconocido.
 */
const props = defineProps<{ day: DayDetail }>();

const partsFormatter = computed(
    () =>
        new Intl.DateTimeFormat('en-CA', {
            timeZone: props.day.timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }),
);

const timeFormatter = computed(
    () =>
        new Intl.DateTimeFormat('es-MX', {
            timeZone: props.day.timezone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }),
);

/** Minutos desde el inicio del día civil (puede exceder 1440 si cruza medianoche). */
function minuteOf(iso: string): number {
    const parts = Object.fromEntries(
        partsFormatter.value
            .formatToParts(new Date(iso))
            .map((p) => [p.type, p.value]),
    );
    const date = `${parts.year}-${parts.month}-${parts.day}`;
    const base = Number(parts.hour) * 60 + Number(parts.minute);
    const diffDays = Math.round(
        (Date.parse(`${date}T00:00:00Z`) -
            Date.parse(`${props.day.date}T00:00:00Z`)) /
            86400000,
    );

    return base + diffDays * 1440;
}

const span = computed(() => {
    const points: number[] = [0, 1440];
    if (props.day.shift) {
        points.push(minuteOf(props.day.shift.ends_at));
    }
    props.day.punches.forEach((p) =>
        points.push(minuteOf(p.recognized_time ?? p.server_time)),
    );

    return Math.min(2880, Math.max(...points));
});

const pct = (m: number) =>
    `${Math.max(0, Math.min(100, (m / span.value) * 100))}%`;

const shift = computed(() => {
    if (!props.day.shift) {
        return null;
    }
    const a = minuteOf(props.day.shift.starts_at);
    const b = minuteOf(props.day.shift.ends_at);

    return { left: pct(a), width: `${((b - a) / span.value) * 100}%` };
});

const ticks = computed(() => {
    const out: number[] = [];
    for (let h = 0; h * 60 <= span.value; h += span.value > 1440 ? 6 : 3) {
        out.push(h);
    }

    return out;
});

const dot = (type: string) =>
    type === 'clock_in'
        ? 'bg-success'
        : type === 'clock_out'
          ? 'bg-danger'
          : 'bg-warning';
const time = (iso: string) => timeFormatter.value.format(new Date(iso));
/** Marcaje registrado por el sistema (salida automática por política). */
const autoUuid = computed(() => props.day.auto_clock_out?.punch_uuid ?? null);
const cutoffAt = computed(() => props.day.auto_clock_out?.cutoff_at ?? null);
</script>

<template>
    <TooltipProvider :delay-duration="80">
        <div class="flex flex-col gap-3">
            <div
                v-for="lane in ['Programado', 'Original', 'Reconocido']"
                :key="lane"
                class="grid grid-cols-[5.5rem_1fr] items-center gap-3"
            >
                <span class="text-xs font-medium text-muted-foreground">{{
                    lane
                }}</span>
                <div
                    class="relative h-7 rounded-lg bg-surface-2 ring-1 ring-border/60"
                >
                    <template v-if="lane === 'Programado'">
                        <div
                            v-if="shift"
                            class="absolute inset-y-1 rounded-md bg-primary/20 ring-1 ring-primary/40"
                            :style="{ left: shift.left, width: shift.width }"
                        />
                        <span
                            v-else
                            class="absolute inset-0 flex items-center justify-center text-[0.7rem] text-muted-foreground"
                            >{{
                                day.holiday
                                    ? `Festivo · ${day.holiday.name}`
                                    : 'Sin turno'
                            }}</span
                        >
                    </template>
                    <template v-else-if="lane === 'Original'">
                        <Tooltip v-for="p in day.punches" :key="p.uuid">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    :class="
                                        cn(
                                            'absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-card',
                                            dot(p.type),
                                            p.voided &&
                                                'opacity-40 ring-danger',
                                            p.status === 'flagged' &&
                                                'outline-2 outline-offset-1 outline-warning',
                                            p.is_system &&
                                                'rounded-sm border-2 border-dashed border-info bg-card ring-0',
                                        )
                                    "
                                    :style="{
                                        left: pct(
                                            minuteOf(
                                                p.recognized_time ??
                                                    p.server_time,
                                            ),
                                        ),
                                    }"
                                    :aria-label="`${p.is_system ? 'Salida automática' : p.type_label} ${time(p.recognized_time ?? p.server_time)}`"
                                />
                            </TooltipTrigger>
                            <TooltipContent>
                                <template v-if="p.is_system">
                                    Salida automática por política ({{
                                        time(p.recognized_time)
                                    }}) · registrada {{ time(p.server_time) }}
                                </template>
                                <template v-else
                                    >{{ p.type_label }} ·
                                    {{ time(p.server_time) }}</template
                                >
                                <template v-if="p.voided"> · excluido</template>
                            </TooltipContent>
                        </Tooltip>
                        <div
                            v-if="cutoffAt"
                            class="pointer-events-none absolute inset-y-0 border-l-2 border-dashed border-info/70"
                            :style="{ left: pct(minuteOf(cutoffAt)) }"
                            aria-hidden="true"
                        />
                    </template>
                    <template v-else>
                        <Tooltip v-for="(e, i) in day.recognized" :key="i">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    :class="
                                        cn(
                                            'absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-card',
                                            dot(e.type),
                                            e.origin !== 'original' &&
                                                'ring-info',
                                            e.punch_uuid !== null &&
                                                e.punch_uuid === autoUuid &&
                                                'rounded-sm border-2 border-dashed border-info bg-card ring-0',
                                        )
                                    "
                                    :style="{ left: pct(minuteOf(e.time)) }"
                                    :aria-label="`${e.type_label} ${time(e.time)}`"
                                />
                            </TooltipTrigger>
                            <TooltipContent>
                                {{
                                    e.punch_uuid !== null &&
                                    e.punch_uuid === autoUuid
                                        ? 'Salida automática por política'
                                        : e.type_label
                                }}
                                · {{ time(e.time) }} · {{ e.origin_label }}
                            </TooltipContent>
                        </Tooltip>
                    </template>
                </div>
            </div>
            <div class="grid grid-cols-[5.5rem_1fr] gap-3">
                <span />
                <div
                    class="relative h-4 numeric text-[0.65rem] text-muted-foreground"
                >
                    <span
                        v-for="h in ticks"
                        :key="h"
                        class="absolute -translate-x-1/2"
                        :style="{ left: pct(h * 60) }"
                        >{{ String(h % 24).padStart(2, '0') }}</span
                    >
                </div>
            </div>
        </div>
    </TooltipProvider>
</template>
