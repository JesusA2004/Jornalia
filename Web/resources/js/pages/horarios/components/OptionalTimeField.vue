<script setup lang="ts">
import { computed } from 'vue';
import { Switch } from '@/components/ui/switch';
import TimeField from './TimeField.vue';

/**
 * Hora opcional (HH:MM o null): un interruptor la activa y muestra el selector.
 * Se usa para la salida automática en puestos, políticas y expedientes.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        invalid?: boolean;
        disabled?: boolean;
        /** Hora sugerida al activar. */
        fallback?: string;
        offLabel?: string;
        ariaLabel?: string;
    }>(),
    { fallback: '20:00', offLabel: 'Sin cierre automático', ariaLabel: 'Hora' },
);

const model = defineModel<string | null>({ default: null });

const enabled = computed({
    get: () => model.value !== null && model.value !== '',
    set: (on: boolean) => {
        model.value = on ? model.value || props.fallback : null;
    },
});

const time = computed({
    get: () => model.value ?? props.fallback,
    set: (value: string) => {
        model.value = value;
    },
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <Switch
            :id="id"
            v-model="enabled"
            :disabled="disabled"
            :aria-label="`Activar ${ariaLabel.toLowerCase()}`"
        />
        <TimeField
            v-if="enabled"
            v-model="time"
            :invalid="invalid"
            :disabled="disabled"
            :aria-label="ariaLabel"
        />
        <span v-else class="text-sm text-muted-foreground">{{ offLabel }}</span>
    </div>
</template>
