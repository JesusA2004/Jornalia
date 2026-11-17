<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/**
 * Hora HH:MM con dos selectores (hora y minuto, pasos de 5 min).
 * Copia local de horarios/components/TimeField.vue.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        invalid?: boolean;
        disabled?: boolean;
        step?: number;
        class?: HTMLAttributes['class'];
        ariaLabel?: string;
    }>(),
    { step: 5, invalid: false, disabled: false, ariaLabel: 'Hora' },
);

const model = defineModel<string>({ default: '09:00' });

const hours = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));

const hour = computed({
    get: () => model.value?.slice(0, 2) ?? '09',
    set: (h: string) => {
        model.value = `${h}:${minute.value}`;
    },
});

const minute = computed({
    get: () => model.value?.slice(3, 5) ?? '00',
    set: (m: string) => {
        model.value = `${hour.value}:${m}`;
    },
});

const minutes = computed(() => {
    const list = Array.from({ length: Math.ceil(60 / props.step) }, (_, i) =>
        String(i * props.step).padStart(2, '0'),
    );

    if (!list.includes(minute.value)) {
        list.push(minute.value);
        list.sort();
    }

    return list;
});

function setHour(value: unknown): void {
    if (typeof value === 'string') {
        hour.value = value;
    }
}

function setMinute(value: unknown): void {
    if (typeof value === 'string') {
        minute.value = value;
    }
}
</script>

<template>
    <div
        :class="
            cn(
                'inline-flex items-center gap-1 rounded-lg',
                invalid && 'ring-2 ring-danger/40',
                props.class,
            )
        "
        role="group"
        :aria-label="ariaLabel"
    >
        <Select
            :model-value="hour"
            :disabled="disabled"
            @update:model-value="setHour"
        >
            <SelectTrigger
                :id="id"
                size="sm"
                class="w-[4.75rem] numeric"
                aria-label="Hora"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent class="max-h-64">
                <SelectItem v-for="h in hours" :key="h" :value="h">
                    {{ h }}
                </SelectItem>
            </SelectContent>
        </Select>
        <span class="text-muted-foreground">:</span>
        <Select
            :model-value="minute"
            :disabled="disabled"
            @update:model-value="setMinute"
        >
            <SelectTrigger
                size="sm"
                class="w-[4.75rem] numeric"
                aria-label="Minutos"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent class="max-h-64">
                <SelectItem v-for="m in minutes" :key="m" :value="m">
                    {{ m }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
