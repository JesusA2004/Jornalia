<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types';

const props = withDefaults(
    defineProps<{
        options: SelectOption[];
        placeholder?: string;
        id?: string;
        /** Nombre para envío con <Form> de Inertia (input oculto). */
        name?: string;
        disabled?: boolean;
        required?: boolean;
        invalid?: boolean;
        /** Muestra un botón para limpiar el valor (filtros). */
        clearable?: boolean;
        /** Etiqueta de grupo dentro del menú. */
        groupLabel?: string;
        /** Prefijo dentro del disparador (p. ej. "Estado:"). */
        prefix?: string;
        size?: 'sm' | 'default';
        /** Sin ancho mínimo (p. ej. selectores de hora y minuto lado a lado). */
        compact?: boolean;
        class?: HTMLAttributes['class'];
        ariaDescribedby?: string;
    }>(),
    {
        placeholder: 'Selecciona una opción',
        size: 'default',
        clearable: false,
        disabled: false,
        required: false,
        invalid: false,
    },
);

const model = defineModel<string | null>({ default: null });

const selected = computed(() =>
    props.options.find((option) => option.value === model.value),
);

/** Evita "Estado: Estado": sin valor, el prefijo sobra si el placeholder ya lo nombra. */
const showPrefix = computed(() => {
    if (!props.prefix) {
        return false;
    }

    if (selected.value) {
        return true;
    }

    const head = (text: string) =>
        text
            .toLocaleLowerCase('es')
            .replace(/[^a-záéíóúñü]/g, '')
            .slice(0, 3);

    return head(props.prefix) !== head(props.placeholder);
});

const value = computed({
    get: () => model.value ?? undefined,
    set: (next) => {
        model.value = next === undefined || next === null ? null : String(next);
    },
});
</script>

<template>
    <div :class="cn('relative w-full min-w-0 sm:w-auto', props.class)">
        <Select
            v-model="value"
            :name="name"
            :disabled="disabled"
            :required="required"
        >
            <SelectTrigger
                :id="id"
                :size="size"
                :aria-invalid="invalid || undefined"
                :aria-describedby="ariaDescribedby"
                :class="
                    cn(
                        'w-full bg-card shadow-elev-1 dark:bg-input/30',
                        !compact && 'min-w-40',
                        clearable && model && 'pr-14',
                    )
                "
            >
                <span
                    v-if="showPrefix"
                    class="shrink-0 text-muted-foreground"
                    >{{ prefix }}</span
                >
                <SelectValue :placeholder="placeholder">
                    <span v-if="selected" class="truncate">{{
                        selected.label
                    }}</span>
                </SelectValue>
            </SelectTrigger>
            <SelectContent class="max-h-80">
                <SelectGroup>
                    <SelectLabel v-if="groupLabel">{{
                        groupLabel
                    }}</SelectLabel>
                    <SelectItem
                        v-for="option in options"
                        :key="option.value"
                        :value="option.value"
                        :disabled="option.disabled"
                    >
                        <div class="grid">
                            <span>{{ option.label }}</span>
                            <span
                                v-if="option.description"
                                class="text-xs text-muted-foreground"
                                >{{ option.description }}</span
                            >
                        </div>
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>
        <button
            v-if="clearable && model && !disabled"
            type="button"
            class="absolute top-1/2 right-8 flex size-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground transition-standard hover:bg-accent hover:text-foreground"
            aria-label="Quitar selección"
            @click.stop="model = null"
        >
            <X class="icon-xs" />
        </button>
    </div>
</template>
