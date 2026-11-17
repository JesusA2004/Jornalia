<script setup lang="ts">
import { Donut } from '@unovis/ts';
import { VisDonut, VisSingleContainer, VisTooltip } from '@unovis/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { ChartContainer } from '@/components/ui/chart';
import { CHART_COLORS, tooltipHtml } from '@/lib/chart';
import { formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChartConfig } from '@/components/ui/chart';

type Row = Record<string, unknown>;

const props = withDefaults(
    defineProps<{
        data: object[];
        /** Clave del nombre del segmento. */
        index: string;
        /** Clave del valor numérico. */
        category: string;
        /** Clave opcional con el color CSS del segmento. */
        colorKey?: string;
        /** Formato del valor (tooltip / leyenda). */
        valueFormatter?: (value: number) => string;
        /** Texto grande central (p. ej. total). */
        centerValue?: string;
        /** Texto pequeño central. */
        centerLabel?: string;
        /** Grosor del anillo en px. */
        arcWidth?: number;
        showLegend?: boolean;
        /** Clase de tamaño del anillo (cuadrado). Default 'size-44'. */
        sizeClass?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        valueFormatter: (value: number) => formatNumber(value),
        arcWidth: 22,
        showLegend: true,
        sizeClass: 'size-44',
    },
);

const rows = computed(() => props.data as Row[]);

const num = (value: unknown): number => {
    const parsed = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
};

const colorOf = (d: Row, i: number): string =>
    (props.colorKey && typeof d[props.colorKey] === 'string'
        ? (d[props.colorKey] as string)
        : undefined) ?? CHART_COLORS[i % CHART_COLORS.length];

const total = computed(() =>
    rows.value.reduce((sum, d) => sum + num(d[props.category]), 0),
);

const segments = computed(() =>
    rows.value.map((d, i) => ({
        key: String(d[props.index] ?? i),
        label: String(d[props.index] ?? ''),
        value: num(d[props.category]),
        color: colorOf(d, i),
    })),
);

const config = computed<ChartConfig>(() =>
    Object.fromEntries(
        segments.value.map((s) => [s.key, { label: s.label, color: s.color }]),
    ),
);

const value = (d: Row) => num(d[props.category]);
const color = (d: Row, i: number) => colorOf(d, i);

const triggers = {
    [Donut.selectors.segment]: (datum: { data: Row; index: number }) => {
        const d = datum.data;
        const v = num(d[props.category]);
        const share = total.value > 0 ? v / total.value : 0;

        return tooltipHtml(null, [
            {
                label: String(d[props.index] ?? ''),
                value: `${props.valueFormatter(v)} · ${formatPercent(share, { isRatio: true })}`,
                color: colorOf(d, datum.index),
            },
        ]);
    },
};
</script>

<template>
    <div :class="cn('@container w-full min-w-0', props.class)">
        <div
            class="flex flex-col items-center gap-6 @md:flex-row @md:items-center"
        >
            <ChartContainer
                :config="config"
                :class="cn('relative aspect-square shrink-0', sizeClass)"
            >
                <VisSingleContainer :data="rows">
                    <VisDonut
                        :value="value"
                        :color="color"
                        :arc-width="arcWidth"
                        :pad-angle="0.02"
                        :corner-radius="4"
                        :show-background="false"
                        :central-label="centerValue"
                        :central-sub-label="centerLabel"
                    />
                    <VisTooltip :triggers="triggers" />
                </VisSingleContainer>
            </ChartContainer>

            <ul
                v-if="showLegend"
                class="grid w-full min-w-0 flex-1 gap-2.5 text-sm"
            >
                <li
                    v-for="segment in segments"
                    :key="segment.key"
                    class="flex items-center gap-2.5"
                >
                    <span
                        class="size-2.5 shrink-0 rounded-[3px]"
                        :style="{ background: segment.color }"
                        aria-hidden="true"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-muted-foreground"
                        >{{ segment.label }}</span
                    >
                    <span class="numeric font-medium text-foreground">{{
                        valueFormatter(segment.value)
                    }}</span>
                    <span
                        class="w-12 text-right numeric text-xs text-subtle-foreground"
                        >{{
                            total > 0
                                ? formatPercent(segment.value / total, {
                                      isRatio: true,
                                      digits: 0,
                                  })
                                : '—'
                        }}</span
                    >
                </li>
            </ul>
        </div>
    </div>
</template>
