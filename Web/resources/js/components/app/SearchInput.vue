<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        placeholder?: string;
        /**
         * Debounce (ms) antes de emitir `update:modelValue`. 0 = inmediato.
         * Con `useFilters` deja 0: el composable ya aplica debounce.
         */
        debounce?: number;
        loading?: boolean;
        /** Etiqueta accesible. */
        label?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        placeholder: 'Buscar…',
        debounce: 0,
        loading: false,
        label: 'Buscar',
    },
);

const model = defineModel<string | null>({ default: '' });
const emit = defineEmits<{ search: [value: string] }>();

const local = ref(model.value ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

watch(model, (value) => {
    if ((value ?? '') !== local.value) {
        local.value = value ?? '';
    }
});

watch(local, (value) => {
    clearTimeout(timer);

    const commit = () => {
        model.value = value;
        emit('search', value);
    };

    if (props.debounce > 0) {
        timer = setTimeout(commit, props.debounce);
    } else {
        commit();
    }
});

onBeforeUnmount(() => clearTimeout(timer));

function clear(): void {
    local.value = '';
}
</script>

<template>
    <div
        :class="
            cn(
                'group/search relative flex h-9 w-full min-w-0 items-center rounded-lg border border-input bg-card shadow-elev-1 transition-standard focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30 sm:max-w-xs dark:bg-input/30',
                props.class,
            )
        "
    >
        <Search
            class="pointer-events-none absolute left-3 icon-sm text-subtle-foreground"
            aria-hidden="true"
        />
        <input
            v-model="local"
            type="search"
            :placeholder="placeholder"
            :aria-label="label"
            class="h-full w-full min-w-0 bg-transparent pr-9 pl-9 text-sm outline-none placeholder:text-subtle-foreground [&::-webkit-search-cancel-button]:hidden"
            @keydown.esc="clear"
        />
        <span class="absolute right-2 flex items-center">
            <Spinner v-if="loading" class="size-4 text-muted-foreground" />
            <button
                v-else-if="local"
                type="button"
                class="flex size-6 items-center justify-center rounded-md text-muted-foreground transition-standard hover:bg-accent hover:text-foreground"
                aria-label="Limpiar búsqueda"
                @click="clear"
            >
                <X class="icon-xs" />
            </button>
        </span>
    </div>
</template>
