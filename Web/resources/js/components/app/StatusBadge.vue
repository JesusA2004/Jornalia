<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    resolveStatus,
    TONE_DOT_CLASSES,
    TONE_SOFT_CLASSES,
} from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types';

const props = withDefaults(
    defineProps<{
        /** Clave del estado (pending, approved, active…). Ver lib/status.ts. */
        status?: string | null;
        /** Sustituye la etiqueta del catálogo. */
        label?: string;
        /** Sustituye el tono del catálogo. */
        tone?: Tone;
        size?: 'sm' | 'md';
        /** Muestra un punto de color antes de la etiqueta. */
        dot?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { size: 'md', dot: true },
);

const resolved = computed(() => {
    const base = resolveStatus(props.status);

    return {
        label: props.label ?? base.label,
        tone: props.tone ?? base.tone,
    };
});
</script>

<template>
    <span
        data-slot="status-badge"
        :data-tone="resolved.tone"
        :class="
            cn(
                'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full border font-medium whitespace-nowrap',
                size === 'sm'
                    ? 'h-5 px-2 text-[0.6875rem]'
                    : 'h-6 px-2.5 text-xs',
                TONE_SOFT_CLASSES[resolved.tone],
                props.class,
            )
        "
    >
        <span
            v-if="dot"
            aria-hidden="true"
            :class="
                cn('size-1.5 rounded-full', TONE_DOT_CLASSES[resolved.tone])
            "
        />
        <slot>{{ resolved.label }}</slot>
    </span>
</template>
