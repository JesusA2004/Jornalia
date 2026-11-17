<script setup lang="ts">
import { useId } from 'vue';
import { Switch } from '@/components/ui/switch';

/** Fila con etiqueta corta, descripción opcional y un interruptor. */
defineProps<{
    label: string;
    description?: string;
    disabled?: boolean;
}>();

const model = defineModel<boolean>({ default: false });
const id = `toggle-${useId()}`;
</script>

<template>
    <div
        class="flex items-start justify-between gap-4 rounded-xl border bg-card px-4 py-3 transition-standard hover:border-border-strong"
    >
        <label :for="id" class="min-w-0 cursor-pointer space-y-0.5">
            <span class="block text-sm font-medium">{{ label }}</span>
            <span
                v-if="description"
                class="block text-xs text-muted-foreground"
                >{{ description }}</span
            >
        </label>
        <Switch
            :id="id"
            v-model="model"
            :disabled="disabled"
            class="mt-0.5 shrink-0"
        />
    </div>
</template>
