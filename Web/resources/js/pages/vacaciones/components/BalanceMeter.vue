<script setup lang="ts">
import { computed } from 'vue';
import type { VacationBalance } from '@/types/operacion';

/** Barra apilada del saldo: disfrutados · pendientes · disponibles. */
const props = withDefaults(
    defineProps<{ balance: VacationBalance; compact?: boolean }>(),
    { compact: false },
);

const pct = (n: number) =>
    props.balance.entitled_days > 0
        ? Math.min(100, (n * 100) / props.balance.entitled_days)
        : 0;
const segments = computed(() => [
    {
        key: 'used',
        label: 'Disfrutados',
        value: props.balance.used_days,
        cls: 'bg-brand',
        width: pct(props.balance.used_days),
    },
    {
        key: 'pending',
        label: 'Pendientes',
        value: props.balance.pending_days,
        cls: 'bg-warning',
        width: pct(props.balance.pending_days),
    },
    {
        key: 'available',
        label: 'Disponibles',
        value: props.balance.available_days,
        cls: 'bg-success',
        width: pct(props.balance.available_days),
    },
]);
</script>

<template>
    <div class="grid gap-1.5">
        <div
            class="flex h-2 w-full overflow-hidden rounded-full bg-surface-3"
            role="img"
            :aria-label="`${balance.available_days} de ${balance.entitled_days} días disponibles`"
        >
            <span
                v-for="s in segments"
                :key="s.key"
                :class="s.cls"
                :style="{ width: `${s.width}%` }"
                class="h-full transition-all"
            />
        </div>
        <div
            v-if="!compact"
            class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
        >
            <span
                v-for="s in segments"
                :key="s.key"
                class="inline-flex items-center gap-1.5"
            >
                <span
                    :class="s.cls"
                    class="size-2 rounded-full"
                    aria-hidden="true"
                />
                {{ s.label }}
                <strong class="numeric text-foreground">{{ s.value }}</strong>
            </span>
        </div>
    </div>
</template>
