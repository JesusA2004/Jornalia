<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowRight, ArrowUpRight, Minus } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import Sparkline from '@/components/app/Sparkline.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatMinutes, formatNumber, formatPercent } from '@/lib/format';
import { TONE_CSS_VAR, TONE_ICON_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types';

const props = withDefaults(
    defineProps<{
        label: string;
        /** Valor ya formateado o número (se formatea con es-MX). */
        value: string | number | null;
        /** Unidad pequeña junto al valor ("colaboradores", "%"). */
        unit?: string;
        /** Línea de contexto bajo el valor. */
        hint?: string;
        icon?: LucideIcon;
        tone?: Tone;
        /** Variación vs. periodo anterior. */
        delta?: number | null;
        /** percent: 12.5 → "+12.5 %" · number · minutes */
        deltaFormat?: 'percent' | 'number' | 'minutes';
        deltaLabel?: string;
        /** Si subir es bueno (verde). false para retardos, faltas… */
        positiveIsGood?: boolean;
        /** Serie para la mini gráfica. */
        sparkline?: number[];
        loading?: boolean;
        /** Convierte la tarjeta en enlace. */
        href?: NonNullable<InertiaLinkProps['href']>;
        class?: HTMLAttributes['class'];
    }>(),
    {
        tone: 'primary',
        delta: null,
        deltaFormat: 'percent',
        deltaLabel: 'vs. periodo anterior',
        positiveIsGood: true,
        loading: false,
    },
);

const displayValue = computed(() => {
    if (props.value === null || props.value === undefined) {
        return '—';
    }

    return typeof props.value === 'number'
        ? formatNumber(props.value)
        : props.value;
});

const deltaText = computed(() => {
    if (props.delta === null || props.delta === undefined) {
        return null;
    }

    switch (props.deltaFormat) {
        case 'minutes':
            return formatMinutes(props.delta, 'short', { signed: true });
        case 'number':
            return formatNumber(props.delta, { signDisplay: 'exceptZero' });
        case 'percent':
        default:
            return formatPercent(props.delta, { signed: true });
    }
});

const trend = computed<'up' | 'down' | 'flat'>(() => {
    if (!props.delta) {
        return 'flat';
    }

    return props.delta > 0 ? 'up' : 'down';
});

const trendClass = computed(() => {
    if (trend.value === 'flat') {
        return 'bg-neutral-soft text-neutral-soft-foreground';
    }

    const good = (trend.value === 'up') === props.positiveIsGood;

    return good
        ? 'bg-success-soft text-success-soft-foreground'
        : 'bg-danger-soft text-danger-soft-foreground';
});

const trendIcon = computed(() =>
    trend.value === 'up'
        ? ArrowUpRight
        : trend.value === 'down'
          ? ArrowDownRight
          : Minus,
);
</script>

<template>
    <component
        :is="href ? Link : 'article'"
        :href="href"
        data-slot="kpi-tile"
        :class="
            cn(
                'group/kpi relative flex min-w-0 flex-col gap-4 overflow-hidden rounded-2xl border bg-card p-5 text-card-foreground shadow-elev-1',
                href &&
                    'transition-standard hover:-translate-y-px hover:border-border-strong hover:shadow-elev-2 focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none',
                props.class,
            )
        "
    >
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm leading-snug font-medium text-muted-foreground">
                {{ label }}
            </p>
            <span
                v-if="icon"
                :class="
                    cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-lg',
                        TONE_ICON_CLASSES[tone],
                    )
                "
            >
                <component :is="icon" class="icon-sm" aria-hidden="true" />
            </span>
        </div>

        <div v-if="loading" class="space-y-2">
            <Skeleton class="h-8 w-24" />
            <Skeleton class="h-4 w-36" />
        </div>
        <div v-else class="space-y-2">
            <p class="flex items-baseline gap-1.5">
                <span
                    class="font-display text-[2rem] leading-none font-bold text-foreground"
                    >{{ displayValue }}</span
                >
                <span v-if="unit" class="text-sm text-muted-foreground">{{
                    unit
                }}</span>
            </p>
            <div
                v-if="deltaText || hint"
                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
            >
                <span
                    v-if="deltaText"
                    :class="
                        cn(
                            'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 numeric font-semibold',
                            trendClass,
                        )
                    "
                >
                    <component
                        :is="trendIcon"
                        class="size-3"
                        aria-hidden="true"
                    />
                    {{ deltaText }}
                </span>
                <span v-if="deltaText" class="text-muted-foreground">{{
                    deltaLabel
                }}</span>
                <span v-else-if="hint" class="text-muted-foreground">{{
                    hint
                }}</span>
            </div>
            <p v-if="hint && deltaText" class="text-xs text-muted-foreground">
                {{ hint }}
            </p>
        </div>

        <Sparkline
            v-if="sparkline && sparkline.length > 1 && !loading"
            :values="sparkline"
            :color="TONE_CSS_VAR[tone === 'neutral' ? 'primary' : tone]"
            class="-mx-1 -mb-1"
        />

        <ArrowRight
            v-if="href"
            class="absolute right-5 bottom-5 icon-sm text-subtle-foreground opacity-0 transition-standard group-hover/kpi:translate-x-0.5 group-hover/kpi:opacity-100"
            aria-hidden="true"
        />
        <slot />
    </component>
</template>
