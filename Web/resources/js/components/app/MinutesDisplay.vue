<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { formatMinutes } from '@/lib/format';
import type { MinutesFormat } from '@/lib/format';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /** Minutos enteros (pueden ser negativos). */
        minutes: number | null | undefined;
        /** long: "7 h 30 min" · short: "7h 30m" · clock: "07:30" · decimal: "7.5 h" */
        format?: MinutesFormat;
        /** Antepone "+" a valores positivos. */
        signed?: boolean;
        /** Colorea positivo/negativo (success/danger). */
        colored?: boolean;
        fallback?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { format: 'long', signed: false, colored: false, fallback: '—' },
);

const text = computed(() =>
    formatMinutes(props.minutes, props.format, {
        signed: props.signed,
        fallback: props.fallback,
    }),
);

const tone = computed(() => {
    if (!props.colored || !props.minutes) {
        return '';
    }

    return props.minutes > 0
        ? 'text-success-soft-foreground'
        : 'text-danger-soft-foreground';
});

const iso = computed(() =>
    props.minutes === null || props.minutes === undefined
        ? undefined
        : `PT${Math.abs(Math.round(props.minutes))}M`,
);
</script>

<template>
    <time
        :datetime="iso"
        :class="cn('numeric whitespace-nowrap', tone, props.class)"
        >{{ text }}</time
    >
</template>
