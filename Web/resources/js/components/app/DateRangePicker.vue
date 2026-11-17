<script setup lang="ts">
import {
    endOfMonth,
    getLocalTimeZone,
    parseDate,
    startOfMonth,
    startOfWeek,
    today,
} from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { CalendarRange, X } from '@lucide/vue';
import type { DateRange } from 'reka-ui';
import { computed, getCurrentInstance, ref } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';
import { formatDate, LOCALE } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DateRangeValue } from '@/types';

type Preset = {
    key: string;
    label: string;
    range: () => [DateValue, DateValue];
};

const props = withDefaults(
    defineProps<{
        placeholder?: string;
        id?: string;
        /** Nombres para envío con <Form> (inputs ocultos YYYY-MM-DD). */
        startName?: string;
        endName?: string;
        min?: string;
        max?: string;
        disabled?: boolean;
        invalid?: boolean;
        clearable?: boolean;
        /** Muestra accesos rápidos (hoy, quincena, mes…). Default true. */
        presets?: boolean;
        /** Meses visibles. Default 2 en escritorio. */
        numberOfMonths?: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        placeholder: 'Selecciona un periodo',
        disabled: false,
        invalid: false,
        clearable: true,
        presets: true,
        numberOfMonths: 2,
    },
);

/**
 * Admite `v-model` ({ start, end }) o `v-model:start` + `v-model:end`
 * (útil con useFilters: filters.from / filters.to).
 */
const model = defineModel<DateRangeValue | null>({ default: null });
const start = defineModel<string | null>('start');
const end = defineModel<string | null>('end');

const open = ref(false);

// ¿El padre usa v-model:start / v-model:end en lugar del objeto?
const vnodeProps = getCurrentInstance()?.vnode.props ?? {};
const usesSplit =
    'onUpdate:start' in vnodeProps || 'onUpdate:end' in vnodeProps;

function safeParse(value: string | null | undefined): DateValue | undefined {
    if (!value) {
        return undefined;
    }

    try {
        return parseDate(value.slice(0, 10));
    } catch {
        return undefined;
    }
}

const current = computed<DateRangeValue>(() =>
    usesSplit
        ? { start: start.value ?? null, end: end.value ?? null }
        : { start: model.value?.start ?? null, end: model.value?.end ?? null },
);

function commit(next: DateRangeValue): void {
    if (usesSplit) {
        start.value = next.start;
        end.value = next.end;
    } else {
        model.value = next.start || next.end ? next : null;
    }
}

const calendarValue = computed<DateRange>({
    get: () => ({
        start: safeParse(current.value.start),
        end: safeParse(current.value.end),
    }),
    set: (value) => {
        const next = {
            start: value.start ? value.start.toString() : null,
            end: value.end ? value.end.toString() : null,
        };

        commit(next);

        if (next.start && next.end) {
            open.value = false;
        }
    },
});

const label = computed(() => {
    const { start: from, end: to } = current.value;

    if (!from && !to) {
        return '';
    }

    if (from && to && from === to) {
        return formatDate(from, 'date');
    }

    return `${from ? formatDate(from, 'date') : '…'} – ${to ? formatDate(to, 'date') : '…'}`;
});

const presetList: Preset[] = [
    {
        key: 'today',
        label: 'Hoy',
        range: () => {
            const t = today(getLocalTimeZone());

            return [t, t];
        },
    },
    {
        key: 'week',
        label: 'Esta semana',
        range: () => {
            const t = today(getLocalTimeZone());

            return [startOfWeek(t, 'es-MX'), t];
        },
    },
    {
        key: 'last7',
        label: 'Últimos 7 días',
        range: () => {
            const t = today(getLocalTimeZone());

            return [t.subtract({ days: 6 }), t];
        },
    },
    {
        key: 'fortnight',
        label: 'Quincena actual',
        range: () => {
            const t = today(getLocalTimeZone());

            return t.day <= 15
                ? [t.set({ day: 1 }), t.set({ day: 15 })]
                : [t.set({ day: 16 }), endOfMonth(t)];
        },
    },
    {
        key: 'month',
        label: 'Este mes',
        range: () => {
            const t = today(getLocalTimeZone());

            return [startOfMonth(t), endOfMonth(t)];
        },
    },
    {
        key: 'prev-month',
        label: 'Mes anterior',
        range: () => {
            const prev = today(getLocalTimeZone()).subtract({ months: 1 });

            return [startOfMonth(prev), endOfMonth(prev)];
        },
    },
];

function applyPreset(preset: Preset): void {
    const [from, to] = preset.range();

    commit({ start: from.toString(), end: to.toString() });
    open.value = false;
}

function clear(): void {
    commit({ start: null, end: null });
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                :disabled="disabled"
                :aria-invalid="invalid || undefined"
                :class="
                    cn(
                        'h-9 w-full min-w-56 justify-start gap-2 bg-card px-3 font-normal shadow-elev-1 sm:w-auto dark:bg-input/30',
                        !label && 'text-muted-foreground',
                        props.class,
                    )
                "
            >
                <CalendarRange
                    class="icon-sm text-subtle-foreground"
                    aria-hidden="true"
                />
                <span class="truncate numeric">{{ label || placeholder }}</span>
                <span
                    v-if="clearable && label && !disabled"
                    role="button"
                    tabindex="-1"
                    aria-label="Quitar periodo"
                    class="ml-auto flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click.stop="clear"
                >
                    <X class="icon-xs" />
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <div class="flex flex-col sm:flex-row">
                <div
                    v-if="presets"
                    class="flex gap-1 overflow-x-auto border-b p-2 sm:w-40 sm:flex-col sm:border-r sm:border-b-0"
                >
                    <p
                        class="hidden px-2 pt-1 pb-2 text-xs font-medium text-muted-foreground sm:block"
                    >
                        Accesos rápidos
                    </p>
                    <Button
                        v-for="preset in presetList"
                        :key="preset.key"
                        variant="ghost"
                        size="sm"
                        class="shrink-0 justify-start font-normal"
                        @click="applyPreset(preset)"
                    >
                        {{ preset.label }}
                    </Button>
                </div>
                <RangeCalendar
                    v-model="calendarValue"
                    :locale="LOCALE"
                    :week-starts-on="1"
                    :number-of-months="numberOfMonths"
                    :min-value="safeParse(min)"
                    :max-value="safeParse(max)"
                    initial-focus
                />
            </div>
        </PopoverContent>
    </Popover>
    <input
        v-if="startName"
        type="hidden"
        :name="startName"
        :value="current.start ?? ''"
    />
    <input
        v-if="endName"
        type="hidden"
        :name="endName"
        :value="current.end ?? ''"
    />
</template>
