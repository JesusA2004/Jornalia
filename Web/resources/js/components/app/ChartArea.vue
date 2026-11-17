<script setup lang="ts">
import {
    VisArea,
    VisAxis,
    VisCrosshair,
    VisLine,
    VisTooltip,
    VisXYContainer,
} from '@unovis/vue';
import { computed, useId } from 'vue';
import type { HTMLAttributes } from 'vue';
import { ChartContainer, ChartLegendContent } from '@/components/ui/chart';
import { seriesColor, toChartConfig, tooltipHtml } from '@/lib/chart';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChartSeries } from '@/types';

type Row = Record<string, unknown>;

const props = withDefaults(
    defineProps<{
        /** Filas de datos (un objeto por punto del eje X). */
        data: object[];
        /** Clave del eje X en cada fila (fecha, etiqueta…). */
        index: string;
        series: ChartSeries[];
        /** Formato de etiquetas del eje X / título del tooltip. */
        xFormatter?: (value: unknown, index: number) => string;
        /** Formato de valores (eje Y y tooltip). */
        valueFormatter?: (value: number) => string;
        /** Apila las series. */
        stacked?: boolean;
        curve?: 'monotoneX' | 'linear' | 'step';
        showLegend?: boolean;
        showYAxis?: boolean;
        /** Clase de altura del gráfico. Default 'h-64'. */
        heightClass?: string;
        /** Máximo de etiquetas visibles en el eje X. */
        maxXTicks?: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        xFormatter: (value: unknown) => String(value ?? ''),
        valueFormatter: (value: number) => formatNumber(value),
        stacked: false,
        curve: 'monotoneX',
        showLegend: true,
        showYAxis: true,
        heightClass: 'h-64',
        maxXTicks: 7,
    },
);

const uid = useId().replace(/[^a-zA-Z0-9_-]/g, '');
const rows = computed(() => props.data as Row[]);
const config = computed(() => toChartConfig(props.series));
const colors = computed(() =>
    props.series.map((item, i) => seriesColor(item, i)),
);

const num = (value: unknown): number => {
    const parsed = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
};

const x = (_: Row, i: number) => i;
const accessors = computed(() =>
    props.series.map((item) => (d: Row) => num(d[item.key])),
);

/** Acumulados para que la línea siga el borde de cada área apilada. */
const stackedAccessors = computed(() =>
    props.series.map(
        (_, si) => (d: Row) =>
            props.series
                .slice(0, si + 1)
                .reduce((sum, item) => sum + num(d[item.key]), 0),
    ),
);

const svgDefs = computed(() =>
    props.series
        .map(
            (_, i) =>
                `<linearGradient id="${uid}-fill-${i}" x1="0" y1="0" x2="0" y2="1">` +
                `<stop offset="0%" style="stop-color:${colors.value[i]};stop-opacity:0.32" />` +
                `<stop offset="95%" style="stop-color:${colors.value[i]};stop-opacity:0.02" />` +
                `</linearGradient>`,
        )
        .join(''),
);

const tickValues = computed(() => {
    const total = rows.value.length;

    if (total <= props.maxXTicks) {
        return rows.value.map((_, i) => i);
    }

    const step = Math.ceil((total - 1) / (props.maxXTicks - 1));
    const values: number[] = [];

    for (let i = 0; i < total; i += step) {
        values.push(i);
    }

    return values;
});

const xTick = (tick: number | Date) => {
    const i = Number(tick);

    return props.xFormatter(rows.value[i]?.[props.index], i);
};

const yTick = (tick: number | Date) => props.valueFormatter(Number(tick));

const template = (d: Row, xValue: number | Date) => {
    const i = Number(xValue);

    return tooltipHtml(
        props.xFormatter(d[props.index], i),
        props.series.map((item, si) => ({
            label: item.label,
            value: props.valueFormatter(num(d[item.key])),
            color: colors.value[si],
        })),
    );
};
</script>

<template>
    <ChartContainer
        :config="config"
        :class="cn('aspect-auto w-full', heightClass, props.class)"
    >
        <VisXYContainer
            :data="rows"
            :svg-defs="svgDefs"
            :margin="{ left: showYAxis ? 4 : 0, right: 8, top: 8 }"
            :y-domain="[0, undefined]"
            :padding="{ top: 8 }"
        >
            <template v-if="stacked">
                <VisArea
                    :x="x"
                    :y="accessors"
                    :color="(_: unknown, i: number) => `url(#${uid}-fill-${i})`"
                    :curve-type="curve"
                />
                <VisLine
                    v-for="(acc, i) in stackedAccessors"
                    :key="`line-${i}`"
                    :x="x"
                    :y="acc"
                    :color="colors[i]"
                    :line-width="2"
                    :curve-type="curve"
                />
            </template>
            <template v-else>
                <template v-for="(acc, i) in accessors" :key="series[i].key">
                    <VisArea
                        :x="x"
                        :y="acc"
                        :color="`url(#${uid}-fill-${i})`"
                        :curve-type="curve"
                    />
                    <VisLine
                        :x="x"
                        :y="acc"
                        :color="colors[i]"
                        :line-width="2"
                        :curve-type="curve"
                    />
                </template>
            </template>

            <VisAxis
                type="x"
                :x="x"
                :tick-values="tickValues"
                :tick-format="xTick"
                :tick-line="false"
                :domain-line="false"
                :grid-line="false"
                :tick-text-hide-overlapping="true"
            />
            <VisAxis
                v-if="showYAxis"
                type="y"
                :num-ticks="4"
                :tick-format="yTick"
                :tick-line="false"
                :domain-line="false"
                :grid-line="true"
            />
            <VisTooltip />
            <VisCrosshair
                :template="template"
                :color="(_: unknown, i: number) => colors[i % colors.length]"
                :y="stacked ? stackedAccessors : accessors"
            />
        </VisXYContainer>
        <ChartLegendContent
            v-if="showLegend && series.length > 1"
            class="pt-4"
        />
    </ChartContainer>
</template>
