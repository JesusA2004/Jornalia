<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { BRAND } from '@/lib/brand';
import { cn } from '@/lib/utils';

/**
 * Logotipo oficial MR LANA (archivo vectorial del manual, sin modificar)
 * con el descriptor de producto "Asistencias" opcional a la derecha.
 */
const props = withDefaults(
    defineProps<{
        /** Altura del logotipo. */
        size?: 'sm' | 'md' | 'lg' | 'xl';
        /** Muestra el descriptor "Asistencias". */
        withProduct?: boolean;
        /** Descriptor en claro (fondos oscuros). */
        inverted?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { size: 'md', withProduct: true, inverted: false },
);

const HEIGHTS = {
    sm: 'h-7',
    md: 'h-9',
    lg: 'h-12',
    xl: 'h-16',
} as const;
</script>

<template>
    <span :class="cn('flex min-w-0 items-center gap-3', props.class)">
        <img
            :src="BRAND.logoUrl"
            :alt="BRAND.name"
            :class="cn('w-auto shrink-0 select-none', HEIGHTS[size])"
            draggable="false"
        />
        <span
            v-if="withProduct"
            :class="
                cn(
                    'border-l pl-3 font-display text-[0.8125rem] leading-none font-semibold tracking-[0.14em] uppercase',
                    inverted
                        ? 'border-white/20 text-white/80'
                        : 'border-border-strong text-brand-deep dark:text-foreground/80',
                )
            "
            >{{ BRAND.product }}</span
        >
    </span>
</template>
