<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { legendSoftClass } from '@/lib/attendance-status';
import { cn } from '@/lib/utils';
import type { ChipKind } from './kinds';

/**
 * Chip de evento del calendario. Colores de la leyenda compartida
 * (`lib/attendance-status`), iguales en Asistencias, Incidencias y Reportes.
 */
const props = defineProps<{
    kind: ChipKind;
    pending?: boolean;
    /** Muestra el texto completo en varias líneas (nombres largos). */
    wrap?: boolean;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <span
        :class="
            cn(
                'flex min-w-0 items-center gap-1 rounded-md border px-1.5 py-0.5 text-[0.7rem] leading-tight font-medium',
                props.wrap
                    ? 'items-start break-words whitespace-normal'
                    : 'truncate',
                legendSoftClass(props.kind),
                props.pending && 'border-dashed opacity-80',
                props.class,
            )
        "
    >
        <slot />
    </span>
</template>
