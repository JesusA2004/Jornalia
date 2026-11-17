<script setup lang="ts">
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { CalendarDays, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDate, LOCALE } from '@/lib/format';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        placeholder?: string;
        id?: string;
        /** Nombre para envío con <Form> (input oculto con YYYY-MM-DD). */
        name?: string;
        /** Fecha mínima/máxima YYYY-MM-DD. */
        min?: string;
        max?: string;
        disabled?: boolean;
        invalid?: boolean;
        clearable?: boolean;
        /** Selectores de mes y año en la cabecera (fechas lejanas). */
        dropdowns?: boolean;
        class?: HTMLAttributes['class'];
        ariaDescribedby?: string;
    }>(),
    {
        placeholder: 'Selecciona una fecha',
        disabled: false,
        invalid: false,
        clearable: false,
        dropdowns: false,
    },
);

/** Fecha civil en formato YYYY-MM-DD (o null). */
const model = defineModel<string | null>({ default: null });
const open = ref(false);

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

const calendarValue = computed({
    get: () => safeParse(model.value),
    set: (value: DateValue | undefined) => {
        model.value = value ? value.toString() : null;

        if (value) {
            open.value = false;
        }
    },
});

const label = computed(() =>
    model.value ? formatDate(model.value, 'date') : '',
);
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
                :aria-describedby="ariaDescribedby"
                :class="
                    cn(
                        'h-9 w-full min-w-44 justify-start gap-2 bg-card px-3 font-normal shadow-elev-1 sm:w-auto dark:bg-input/30',
                        !model && 'text-muted-foreground',
                        props.class,
                    )
                "
            >
                <CalendarDays
                    class="icon-sm text-subtle-foreground"
                    aria-hidden="true"
                />
                <span class="truncate numeric">{{ label || placeholder }}</span>
                <span
                    v-if="clearable && model && !disabled"
                    role="button"
                    tabindex="-1"
                    aria-label="Quitar fecha"
                    class="ml-auto flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click.stop="model = null"
                >
                    <X class="icon-xs" />
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                v-model="calendarValue"
                :locale="LOCALE"
                :week-starts-on="1"
                :min-value="safeParse(min)"
                :max-value="safeParse(max)"
                :default-placeholder="
                    calendarValue ?? today(getLocalTimeZone())
                "
                :layout="dropdowns ? 'month-and-year' : undefined"
                initial-focus
            />
        </PopoverContent>
    </Popover>
    <input v-if="name" type="hidden" :name="name" :value="model ?? ''" />
</template>
