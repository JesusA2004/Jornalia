<script setup lang="ts">
import { VisArea, VisLine, VisXYContainer } from '@unovis/vue';
import { computed, useId } from 'vue';
import type { HTMLAttributes } from 'vue';
import { ChartContainer } from '@/components/ui/chart';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        values: number[];
        /** Color CSS. Default var(--chart-1). */
        color?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { color: 'var(--chart-1)' },
);

const uid = useId().replace(/[^a-zA-Z0-9_-]/g, '');
const data = computed(() => props.values.map((value, i) => ({ i, value })));
const x = (d: { i: number }) => d.i;
const y = (d: { value: number }) => d.value;
/** Dominio ajustado a los datos para que la tendencia sea visible. */
const yDomain = computed<[number, number]>(() => {
    const min = Math.min(...props.values);
    const max = Math.max(...props.values);
    const pad = (max - min || Math.abs(max) || 1) * 0.15;

    return [min - pad, max + pad];
});

const svgDefs = computed(
    () =>
        `<linearGradient id="${uid}-spark" x1="0" y1="0" x2="0" y2="1">` +
        `<stop offset="0%" style="stop-color:${props.color};stop-opacity:0.28" />` +
        `<stop offset="100%" style="stop-color:${props.color};stop-opacity:0" />` +
        `</linearGradient>`,
);
</script>

<template>
    <ChartContainer
        :config="{ value: { label: 'Valor', color } }"
        :class="cn('pointer-events-none aspect-auto h-10 w-full', props.class)"
        aria-hidden="true"
    >
        <VisXYContainer
            :data="data"
            :svg-defs="svgDefs"
            :y-domain="yDomain"
            :margin="{ top: 2, bottom: 2, left: 0, right: 0 }"
        >
            <VisArea
                :x="x"
                :y="y"
                :color="`url(#${uid}-spark)`"
                curve-type="monotoneX"
            />
            <VisLine
                :x="x"
                :y="y"
                :color="color"
                :line-width="1.75"
                curve-type="monotoneX"
            />
        </VisXYContainer>
    </ChartContainer>
</template>
