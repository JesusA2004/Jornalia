<script setup lang="ts">
import { Check, ChevronsUpDown, X } from '@lucide/vue';
import { computed, onBeforeUnmount } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxList,
    ComboboxTrigger,
    ComboboxViewport,
} from '@/components/ui/combobox';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types';

const props = withDefaults(
    defineProps<{
        options: SelectOption[];
        /** Selección múltiple (v-model: string[]). */
        multiple?: boolean;
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        id?: string;
        /** Nombre para envío con <Form> (input oculto). */
        name?: string;
        disabled?: boolean;
        invalid?: boolean;
        clearable?: boolean;
        /**
         * Búsqueda remota: desactiva el filtro local y emite `search`
         * (con debounce) para que la página recargue `options`.
         */
        remote?: boolean;
        searchDebounce?: number;
        loading?: boolean;
        class?: HTMLAttributes['class'];
        ariaDescribedby?: string;
    }>(),
    {
        multiple: false,
        placeholder: 'Selecciona…',
        searchPlaceholder: 'Buscar…',
        emptyText: 'Sin resultados.',
        disabled: false,
        invalid: false,
        clearable: false,
        remote: false,
        searchDebounce: 300,
        loading: false,
    },
);

const model = defineModel<string | string[] | null>({ default: null });
const emit = defineEmits<{ search: [term: string] }>();

const selectedValues = computed<string[]>(() => {
    if (Array.isArray(model.value)) {
        return model.value;
    }

    return model.value ? [model.value] : [];
});

const labelFor = (value: string) =>
    props.options.find((option) => option.value === value)?.label ?? value;

const display = computed(() => {
    if (selectedValues.value.length === 0) {
        return '';
    }

    if (!props.multiple) {
        return labelFor(selectedValues.value[0]);
    }

    if (selectedValues.value.length <= 2) {
        return selectedValues.value.map(labelFor).join(', ');
    }

    return `${selectedValues.value.length} seleccionados`;
});

const rootValue = computed({
    get: () =>
        props.multiple ? selectedValues.value : (model.value ?? undefined),
    set: (next: unknown) => {
        if (props.multiple) {
            model.value = Array.isArray(next) ? next.map(String) : [];
        } else {
            model.value =
                next === undefined || next === null ? null : String(next);
        }
    },
});

let timer: ReturnType<typeof setTimeout> | undefined;

function onSearch(term: string): void {
    if (!props.remote) {
        return;
    }

    clearTimeout(timer);
    timer = setTimeout(() => emit('search', term), props.searchDebounce);
}

onBeforeUnmount(() => clearTimeout(timer));

function clear(): void {
    model.value = props.multiple ? [] : null;
}
</script>

<template>
    <Combobox
        v-model="rootValue"
        :multiple="multiple"
        :disabled="disabled"
        :name="name"
        :ignore-filter="remote"
        :reset-search-term-on-blur="true"
        :class="cn('relative w-full min-w-0 sm:w-auto', props.class)"
    >
        <ComboboxAnchor as-child>
            <ComboboxTrigger
                :id="id"
                as="button"
                type="button"
                :aria-invalid="invalid || undefined"
                :aria-describedby="ariaDescribedby"
                :class="
                    cn(
                        'flex h-9 w-full min-w-48 items-center justify-between gap-2 rounded-md border border-input bg-card px-3 text-left text-sm shadow-elev-1 transition-standard outline-none hover:border-border-strong focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30',
                    )
                "
            >
                <span
                    :class="cn('truncate', !display && 'text-muted-foreground')"
                    >{{ display || placeholder }}</span
                >
                <span class="flex shrink-0 items-center gap-1">
                    <span
                        v-if="
                            clearable && selectedValues.length > 0 && !disabled
                        "
                        role="button"
                        tabindex="-1"
                        class="flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Quitar selección"
                        @pointerdown.stop.prevent="clear"
                    >
                        <X class="icon-xs" />
                    </span>
                    <ChevronsUpDown
                        class="icon-sm opacity-50"
                        aria-hidden="true"
                    />
                </span>
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxList
            align="start"
            class="w-(--reka-combobox-trigger-width) min-w-64 p-0"
        >
            <ComboboxInput
                :placeholder="searchPlaceholder"
                class="h-10"
                @update:model-value="(value) => onSearch(String(value ?? ''))"
            />
            <ComboboxViewport class="max-h-72 p-1">
                <div
                    v-if="loading"
                    class="flex items-center justify-center gap-2 py-6 text-sm text-muted-foreground"
                >
                    <Spinner class="size-4" /> Buscando…
                </div>
                <template v-else>
                    <ComboboxEmpty
                        class="py-6 text-center text-sm text-muted-foreground"
                        >{{ emptyText }}</ComboboxEmpty
                    >
                    <ComboboxGroup>
                        <ComboboxItem
                            v-for="option in options"
                            :key="option.value"
                            :value="option.value"
                            :text-value="`${option.label} ${option.keywords ?? ''} ${option.description ?? ''}`"
                            :disabled="option.disabled"
                            class="py-2"
                        >
                            <div class="grid min-w-0 flex-1">
                                <span class="truncate">{{ option.label }}</span>
                                <span
                                    v-if="option.description"
                                    class="truncate text-xs text-muted-foreground"
                                    >{{ option.description }}</span
                                >
                            </div>
                            <ComboboxItemIndicator class="ml-auto">
                                <Check class="icon-sm text-brand-strong" />
                            </ComboboxItemIndicator>
                        </ComboboxItem>
                    </ComboboxGroup>
                </template>
            </ComboboxViewport>
        </ComboboxList>
    </Combobox>
</template>
