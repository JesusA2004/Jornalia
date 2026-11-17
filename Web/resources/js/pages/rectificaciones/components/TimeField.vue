<script setup lang="ts">
import { computed } from 'vue';
import SelectField from '@/components/app/SelectField.vue';
import type { SelectOption } from '@/types';

/**
 * Hora HH:MM con dos selectores (hora / minuto) de shadcn-vue: evita el
 * control nativo de hora y funciona igual en todos los navegadores.
 */
defineProps<{ id?: string; invalid?: boolean; disabled?: boolean }>();

const model = defineModel<string | null>({ default: null });

const hours: SelectOption[] = Array.from({ length: 24 }, (_, h) => {
    const v = String(h).padStart(2, '0');

    return { value: v, label: v };
});
const minutes: SelectOption[] = Array.from({ length: 60 }, (_, m) => {
    const v = String(m).padStart(2, '0');

    return { value: v, label: v };
});

const hour = computed<string | null>({
    get: () => model.value?.slice(0, 2) ?? null,
    set: (h) => {
        model.value = h ? `${h}:${model.value?.slice(3, 5) ?? '00'}` : null;
    },
});
const minute = computed<string | null>({
    get: () => model.value?.slice(3, 5) ?? null,
    set: (m) => {
        model.value = m
            ? `${model.value?.slice(0, 2) ?? '00'}:${m}`
            : model.value;
    },
});
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <SelectField
            :id="id"
            v-model="hour"
            :options="hours"
            placeholder="HH"
            :invalid="invalid"
            :disabled="disabled"
            compact
            class="w-auto min-w-0 flex-1"
        />
        <span class="font-semibold text-muted-foreground" aria-hidden="true"
            >:</span
        >
        <SelectField
            v-model="minute"
            :options="minutes"
            placeholder="MM"
            :invalid="invalid"
            :disabled="disabled || !hour"
            compact
            class="w-auto min-w-0 flex-1"
        />
    </div>
</template>
