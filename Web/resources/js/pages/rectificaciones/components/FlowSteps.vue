<script setup lang="ts">
import { Check } from '@lucide/vue';
import { cn } from '@/lib/utils';
import { RECTIFICATION_STEPS } from './stages';

/**
 * Indicador de los pasos del flujo. `current` = índice del paso en curso
 * (-1 sin resaltar, 6 = todos completos).
 */
withDefaults(defineProps<{ current?: number; rejected?: boolean }>(), {
    current: -1,
    rejected: false,
});
</script>

<template>
    <ol
        class="grid grid-cols-3 gap-2 rounded-xl border bg-card p-3 sm:grid-cols-6"
        aria-label="Flujo de rectificación"
    >
        <li
            v-for="(step, i) in RECTIFICATION_STEPS"
            :key="step.key"
            :aria-current="i === current ? 'step' : undefined"
            class="flex min-w-0 items-center gap-2"
        >
            <span
                :class="
                    cn(
                        'grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold',
                        current >= 0 && i < current
                            ? 'bg-success text-white'
                            : i === current && !rejected
                              ? 'bg-brand text-brand-foreground ring-4 ring-brand/15'
                              : 'bg-surface-3 text-muted-foreground',
                    )
                "
            >
                <Check
                    v-if="current >= 0 && i < current"
                    class="size-3.5"
                    aria-hidden="true"
                />
                <template v-else>{{ i + 1 }}</template>
            </span>
            <span class="grid min-w-0 leading-tight">
                <span class="truncate text-xs font-semibold">{{
                    step.label
                }}</span>
                <span class="truncate text-[0.6875rem] text-muted-foreground">{{
                    step.hint
                }}</span>
            </span>
        </li>
    </ol>
</template>
