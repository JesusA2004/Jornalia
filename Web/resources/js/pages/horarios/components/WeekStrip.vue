<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { dayLabel, toMinutes } from '../types';
import type { ScheduleSegment } from '../types';

/**
 * Mini línea de tiempo de 24 h por día del ciclo. Los turnos que cruzan
 * medianoche se dibujan en dos tramos (hasta 24:00 y desde 00:00 del día siguiente).
 */
const props = withDefaults(
    defineProps<{
        segments: Pick<
            ScheduleSegment,
            'day_index' | 'starts_at' | 'ends_at'
        >[];
        cycle: number;
        /** Máximo de días a mostrar. */
        maxDays?: number;
        compact?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { maxDays: 14, compact: false },
);

type Bar = { left: number; width: number; overnight: boolean };

const days = computed(() => {
    const count = Math.min(props.cycle, props.maxDays);
    const rows: { index: number; bars: Bar[] }[] = Array.from(
        { length: count },
        (_, index) => ({ index, bars: [] }),
    );

    for (const s of props.segments) {
        const a = toMinutes(s.starts_at);
        const b = toMinutes(s.ends_at);

        if (a === null || b === null) {
            continue;
        }

        if (b > a) {
            rows[s.day_index]?.bars.push({
                left: (a / 1440) * 100,
                width: ((b - a) / 1440) * 100,
                overnight: false,
            });
        } else {
            rows[s.day_index]?.bars.push({
                left: (a / 1440) * 100,
                width: ((1440 - a) / 1440) * 100,
                overnight: true,
            });
            rows[(s.day_index + 1) % props.cycle]?.bars.push({
                left: 0,
                width: (b / 1440) * 100,
                overnight: true,
            });
        }
    }

    return rows;
});
</script>

<template>
    <div :class="cn('space-y-1', props.class)">
        <div
            v-for="day in days"
            :key="day.index"
            class="flex items-center gap-2"
        >
            <span
                :class="
                    cn(
                        'w-8 shrink-0 text-[0.6875rem] font-semibold text-muted-foreground',
                        compact && 'w-7',
                    )
                "
                >{{ dayLabel(day.index, cycle, true) }}</span
            >
            <div
                :class="
                    cn(
                        'relative flex-1 overflow-hidden rounded-full bg-surface-3',
                        compact ? 'h-1.5' : 'h-2.5',
                    )
                "
            >
                <span
                    v-for="(bar, i) in day.bars"
                    :key="i"
                    :class="
                        cn(
                            'absolute inset-y-0 rounded-full',
                            bar.overnight ? 'bg-info' : 'bg-brand',
                        )
                    "
                    :style="{ left: `${bar.left}%`, width: `${bar.width}%` }"
                />
                <span
                    v-if="day.bars.length === 0 && !compact"
                    class="absolute inset-0 flex items-center justify-center text-[0.5625rem] font-medium tracking-wide text-muted-foreground/70 uppercase"
                    >descanso</span
                >
            </div>
        </div>
        <p
            v-if="cycle > maxDays"
            class="pl-10 text-[0.6875rem] text-muted-foreground"
        >
            +{{ cycle - maxDays }} días más del ciclo
        </p>
    </div>
</template>
