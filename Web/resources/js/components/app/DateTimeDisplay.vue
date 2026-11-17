<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    browserTimeZone,
    formatDateTime,
    formatRelative,
    toDate,
} from '@/lib/format';
import type { DateTimeFormat } from '@/lib/format';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /** ISO 8601 en UTC (o fecha civil YYYY-MM-DD). */
        value: string | number | Date | null | undefined;
        /** Zona IANA de presentación. Default: organización activa → navegador. */
        timezone?: string | null;
        format?: DateTimeFormat | 'relative';
        /** Añade abreviatura de zona ("CST"). */
        showZone?: boolean;
        /** Tooltip con fecha completa y zona. Default true. */
        tooltip?: boolean;
        fallback?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        timezone: undefined,
        format: 'datetime',
        showZone: false,
        tooltip: true,
        fallback: '—',
    },
);

const page = usePage();

const zone = computed(
    () =>
        props.timezone ??
        page.props.auth?.organization?.timezone ??
        browserTimeZone(),
);

const date = computed(() => toDate(props.value));

const text = computed(() => {
    if (!date.value) {
        return props.fallback;
    }

    if (props.format === 'relative') {
        return formatRelative(date.value);
    }

    return formatDateTime(props.value, {
        format: props.format,
        timeZone: zone.value,
        withZone: props.showZone,
    });
});

const full = computed(() =>
    date.value
        ? `${formatDateTime(props.value, { format: 'datetime-long', timeZone: zone.value })} · ${zone.value}`
        : '',
);
</script>

<template>
    <span v-if="!date" :class="cn('text-muted-foreground', props.class)">{{
        fallback
    }}</span>
    <TooltipProvider v-else-if="tooltip" :delay-duration="300">
        <Tooltip>
            <TooltipTrigger as-child>
                <time
                    :datetime="date.toISOString()"
                    :data-allow-mismatch="
                        format === 'relative' ? 'text' : undefined
                    "
                    :class="cn('numeric whitespace-nowrap', props.class)"
                    >{{ text }}</time
                >
            </TooltipTrigger>
            <TooltipContent class="first-letter:uppercase">{{
                full
            }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
    <time
        v-else
        :datetime="date.toISOString()"
        :data-allow-mismatch="format === 'relative' ? 'text' : undefined"
        :class="cn('numeric whitespace-nowrap', props.class)"
        >{{ text }}</time
    >
</template>
