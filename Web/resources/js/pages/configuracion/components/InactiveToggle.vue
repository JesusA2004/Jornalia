<script setup lang="ts">
import { Archive } from '@lucide/vue';
import { Toggle } from '@/components/ui/toggle';

/** Interruptor "Ver inactivos" con contador. */
defineProps<{ count: number }>();
const model = defineModel<boolean>({ default: false });
</script>

<template>
    <Toggle
        v-if="count > 0"
        v-model="model"
        variant="outline"
        size="sm"
        aria-label="Ver inactivos"
    >
        <Archive class="icon-sm" aria-hidden="true" />
        Inactivos
        <span
            class="ml-0.5 rounded-full bg-surface-3 px-1.5 text-[0.6875rem] tabular-nums"
            >{{ count }}</span
        >
    </Toggle>
</template>
