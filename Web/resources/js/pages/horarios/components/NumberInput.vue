<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import {
    NumberField,
    NumberFieldContent,
    NumberFieldDecrement,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/components/ui/number-field';
import { cn } from '@/lib/utils';

/** Campo numérico con botones − / + (NumberField de shadcn-vue). */
const props = withDefaults(
    defineProps<{
        id?: string;
        min?: number;
        max?: number;
        step?: number;
        suffix?: string;
        invalid?: boolean;
        disabled?: boolean;
        ariaLabel?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        min: 0,
        max: undefined,
        step: 1,
        suffix: undefined,
        invalid: false,
        disabled: false,
    },
);

const model = defineModel<number>({ default: 0 });

function update(value: number | undefined): void {
    model.value =
        typeof value === 'number' && Number.isFinite(value) ? value : props.min;
}
</script>

<template>
    <div :class="cn('flex items-center gap-2', props.class)">
        <NumberField
            :id="id"
            :model-value="model"
            :min="min"
            :max="max"
            :step="step"
            :disabled="disabled"
            :aria-label="ariaLabel"
            locale="es-MX"
            class="w-full min-w-28"
            @update:model-value="update"
        >
            <NumberFieldContent>
                <NumberFieldDecrement />
                <NumberFieldInput
                    :aria-invalid="invalid || undefined"
                    class="numeric"
                />
                <NumberFieldIncrement />
            </NumberFieldContent>
        </NumberField>
        <span v-if="suffix" class="shrink-0 text-xs text-muted-foreground">{{
            suffix
        }}</span>
    </div>
</template>
