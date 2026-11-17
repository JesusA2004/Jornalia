<script setup lang="ts">
import { FilterX } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        /** Número de filtros activos (de `useFilters().activeCount`). */
        activeCount?: number;
        resetLabel?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { activeCount: 0, resetLabel: 'Limpiar filtros' },
);

const emit = defineEmits<{ reset: [] }>();
</script>

<template>
    <div
        role="search"
        :class="
            cn(
                'flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center',
                $props.class,
            )
        "
    >
        <div v-if="$slots.search" class="w-full md:w-auto md:min-w-64">
            <slot name="search" />
        </div>
        <div
            v-if="$slots.default"
            class="flex flex-wrap items-center gap-2 [&>*]:min-w-0"
        >
            <slot />
        </div>
        <Button
            v-if="activeCount > 0"
            variant="ghost"
            size="sm"
            class="h-9 w-fit text-muted-foreground hover:text-foreground"
            @click="emit('reset')"
        >
            <FilterX aria-hidden="true" />
            {{ resetLabel }}
            <span
                class="ml-0.5 rounded-full bg-primary-soft px-1.5 numeric text-[0.6875rem] font-semibold text-primary-soft-foreground"
                >{{ activeCount }}</span
            >
        </Button>
        <div v-if="$slots.actions" class="flex items-center gap-2 md:ml-auto">
            <slot name="actions" />
        </div>
    </div>
</template>
