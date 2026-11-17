<script setup lang="ts">
import {
    NumberField,
    NumberFieldContent,
    NumberFieldDecrement,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/components/ui/number-field';

/** Campo numérico con botones +/− y unidad opcional. */
withDefaults(
    defineProps<{
        id?: string;
        min?: number;
        max?: number;
        step?: number;
        suffix?: string;
        disabled?: boolean;
        invalid?: boolean;
    }>(),
    { step: 1 },
);

const model = defineModel<number | null>({ default: null });
</script>

<template>
    <div class="flex items-center gap-2">
        <NumberField
            :id="id"
            :model-value="model ?? undefined"
            :min="min"
            :max="max"
            :step="step"
            :disabled="disabled"
            locale="es-MX"
            class="w-full"
            @update:model-value="
                (v: number | undefined) =>
                    (model = v === undefined || Number.isNaN(v) ? null : v)
            "
        >
            <NumberFieldContent>
                <NumberFieldDecrement />
                <NumberFieldInput :aria-invalid="invalid ? true : undefined" />
                <NumberFieldIncrement />
            </NumberFieldContent>
        </NumberField>
        <span
            v-if="suffix"
            class="shrink-0 text-xs font-medium text-muted-foreground"
            >{{ suffix }}</span
        >
    </div>
</template>
