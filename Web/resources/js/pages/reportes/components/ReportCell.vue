<script setup lang="ts">
import { computed } from 'vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import MinutesDisplay from '@/components/app/MinutesDisplay.vue';
import { formatDate, formatNumber, formatPercent } from '@/lib/format';
import HashChip from '@/pages/integridad/components/HashChip.vue';
import type { ReportColumnType } from '@/types/operacion';

/** Celda de vista previa formateada según el tipo de columna del catálogo. */
const props = defineProps<{
    value: string | number | null | undefined;
    type: ReportColumnType;
    timezone: string;
}>();

const empty = computed(
    () =>
        props.value === null || props.value === undefined || props.value === '',
);
const num = computed(() => Number(props.value ?? 0));
</script>

<template>
    <span v-if="empty" class="text-muted-foreground/60">—</span>
    <MinutesDisplay
        v-else-if="type === 'minutes'"
        :minutes="num"
        format="clock"
        :class="num === 0 ? 'text-muted-foreground/70' : ''"
    />
    <DateTimeDisplay
        v-else-if="type === 'datetime'"
        :value="String(value)"
        :timezone="timezone"
        format="datetime"
    />
    <DateTimeDisplay
        v-else-if="type === 'time'"
        :value="String(value)"
        :timezone="timezone"
        format="time"
    />
    <span v-else-if="type === 'date'" class="whitespace-nowrap">{{
        formatDate(String(value), 'date')
    }}</span>
    <span v-else-if="type === 'number'" class="numeric">{{
        formatNumber(num)
    }}</span>
    <span
        v-else-if="type === 'percent'"
        :class="[
            'numeric font-medium',
            num >= 95
                ? 'text-success-soft-foreground'
                : num >= 85
                  ? 'text-foreground'
                  : 'text-warning-soft-foreground',
        ]"
        >{{ formatPercent(num) }}</span
    >
    <HashChip
        v-else-if="type === 'hash'"
        :value="String(value)"
        :head="8"
        :tail="4"
    />
    <span v-else class="line-clamp-2 max-w-[22rem]">{{ value }}</span>
</template>
