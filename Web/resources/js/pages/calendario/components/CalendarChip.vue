<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import type { ChipKind } from './kinds';

/** Chip de evento del calendario con paleta por tipo. */
const props = defineProps<{
    kind: ChipKind;
    pending?: boolean;
    class?: HTMLAttributes['class'];
}>();

const KIND_CLASSES: Record<ChipKind, string> = {
    shift: 'bg-primary-soft text-primary-soft-foreground border-primary/25',
    rest: 'bg-neutral-soft text-muted-foreground border-border',
    holiday: 'bg-brand-soft text-brand-soft-foreground border-brand/30',
    vacation: 'bg-info-soft text-info-soft-foreground border-info/30',
    leave: 'bg-success-soft text-success-soft-foreground border-success/30',
    disability: 'bg-danger-soft text-danger-soft-foreground border-danger/30',
    incidence: 'bg-warning-soft text-warning-soft-foreground border-warning/30',
    none: 'bg-transparent text-subtle-foreground border-dashed border-border',
};
</script>

<template>
    <span
        :class="
            cn(
                'flex min-w-0 items-center gap-1 truncate rounded-md border px-1.5 py-0.5 text-[0.7rem] leading-tight font-medium',
                KIND_CLASSES[props.kind],
                props.pending && 'border-dashed opacity-80',
                props.class,
            )
        "
    >
        <slot />
    </span>
</template>
