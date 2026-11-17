<script setup lang="ts">
import { computed, useId } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        label?: string;
        /** id del control. Si se omite se genera uno y se expone en el slot. */
        for?: string;
        description?: string;
        /** Mensaje de error (p. ej. `errors.email` de <Form>). */
        error?: string | string[] | null;
        required?: boolean;
        /** Texto a la derecha de la etiqueta ("Opcional", contador…). */
        hint?: string;
        orientation?: 'vertical' | 'horizontal';
        class?: HTMLAttributes['class'];
    }>(),
    { orientation: 'vertical', error: null },
);

const generatedId = useId();
const id = computed(() => props.for ?? `field-${generatedId}`);
const descriptionId = computed(() => `${id.value}-description`);
const errorId = computed(() => `${id.value}-error`);
const errorText = computed(() =>
    Array.isArray(props.error) ? props.error[0] : props.error,
);
const describedBy = computed(
    () =>
        [
            props.description ? descriptionId.value : null,
            errorText.value ? errorId.value : null,
        ]
            .filter(Boolean)
            .join(' ') || undefined,
);
</script>

<template>
    <div
        data-slot="form-field"
        :data-invalid="errorText ? true : undefined"
        :class="
            cn(
                'group/field grid gap-2',
                orientation === 'horizontal' &&
                    'sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)] sm:items-start sm:gap-x-8',
                props.class,
            )
        "
    >
        <div
            v-if="label || hint"
            :class="
                cn(
                    'flex items-baseline justify-between gap-3',
                    orientation === 'horizontal' &&
                        'sm:flex-col sm:gap-1 sm:pt-2',
                )
            "
        >
            <label
                v-if="label"
                :for="id"
                class="text-sm leading-none font-medium text-foreground select-none group-data-[invalid]/field:text-danger-soft-foreground"
            >
                {{ label }}
                <span
                    v-if="required"
                    class="ml-0.5 text-danger"
                    aria-hidden="true"
                    >*</span
                >
            </label>
            <span v-if="hint" class="text-xs text-muted-foreground">{{
                hint
            }}</span>
        </div>
        <div class="grid min-w-0 gap-1.5">
            <slot
                :id="id"
                :invalid="Boolean(errorText)"
                :described-by="describedBy"
            />
            <p
                v-if="description && !errorText"
                :id="descriptionId"
                class="text-xs leading-relaxed text-muted-foreground"
            >
                {{ description }}
            </p>
            <p
                v-if="errorText"
                :id="errorId"
                role="alert"
                class="text-xs font-medium text-danger-soft-foreground"
            >
                {{ errorText }}
            </p>
        </div>
    </div>
</template>
