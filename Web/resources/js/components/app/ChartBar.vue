<script setup lang="ts">
import {
    VisAxis,
    VisCrosshair,
    VisGroupedBar,
    VisStackedBar,
    VisTooltip,
    VisXYContainer,
} from '@unovis/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { ChartContainer, ChartLegendContent } from '@/components/ui/chart';
import { seriesColor, toChartConfig, tooltipHtml } from '@/lib/chart';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChartSeries } from '@/types';

type Row = Record<string, unknown>;

const props = withDefaults(
    defineProps<{
        data: object[];
        /** Clave de la categoría (eje X) en cada fila. */
        index: string;
        series: ChartSeries[];
        xFormatter?: (value: unknown, index: number) => string;
        valueFormatter?: (value: number) => string;
        /** Barras apiladas en lugar de agrupadas. */
        stacked?: boolean;
        showLegend?: boolean;
        showYAxis?: boolean;
        heightClass?: string;
        maxXTicks?: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        xFormatter: (value: unknown) => String(value ?? ''),
        valueFormatter: (value: number) => formatNumber(value),
        stacked: false,
        showLegend: true,
        showYAxis: true,
        heightClass: 'h-64',
        maxXTicks: 12,
    },
);

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
const barColor = (_: Row, i: number) => colors.value[i % colors.value.length];

const tickValues = computed(() => {
    const total = rows.value.length;

    if (total <= props.maxXTicks) {
        return rows.value.map((_, i) => i);
    }

    const step = Math.ceil(total / props.maxXTicks);

    return rows.value.map((_, i) => i).filter((i) => i % step === 0);
});

const xTick = (tick: number | Date) => {
    const i = Number(tick);

    return props.xFormatter(rows.value[i]?.[props.index], i);
};

const yTick = (tick: number | Date) => props.valueFormatter(Number(tick));

const template = (d: Row, xValue: number | Date) =>
    tooltipHtml(
        props.xFormatter(d[props.index], Number(xValue)),
        props.series.map((item, si) => ({
            label: item.label,
            value: props.valueFormatter(num(d[item.key])),
            color: colors.value[si],
        })),
    );
</script>

<template>
    <ChartContainer
        :config="config"
        :class="cn('aspect-auto w-full', heightClass, props.class)"
    >
        <VisXYContainer
            :data="rows"
            :margin="{ left: showYAxis ? 4 : 0, right: 8, top: 8 }"
            :y-domain="[0, undefined]"
        >
            <VisStackedBar
                v-if="stacked"
                :x="x"
                :y="accessors"
                :color="barColor"
                :rounded-corners="4"
                :bar-padding="0.25"
            />
            <VisGroupedBar
                v-else
                :x="x"
                :y="accessors"
                :color="barColor"
                :rounded-corners="4"
                :group-padding="0.2"
                :bar-padding="0.15"
            />
            <VisAxis
                type="x"
                :x="x"
                :tick-values="tickValues"
                :tick-format="xTick"
                :tick-line="false"
                :domain-line="false"
                :grid-line="false"
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
                color="#0000"
                stroke-color="#0000"
            />
        </VisXYContainer>
        <ChartLegendContent
            v-if="showLegend && series.length > 1"
            class="pt-4"
        />
    </ChartContainer>
</template>
