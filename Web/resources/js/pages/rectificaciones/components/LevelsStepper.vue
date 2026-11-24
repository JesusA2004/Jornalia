<script setup lang="ts">
import { Ban, Check, Clock, X } from '@lucide/vue';
import { cn } from '@/lib/utils';
import type { ApprovalLevel } from '@/types/operacion';

/** Flujo de autorización multinivel con el estado de cada nivel. */
defineProps<{ levels: ApprovalLevel[] }>();

const STATE = {
    done: { icon: Check, ring: 'bg-success text-white', text: 'Aprobado' },
    current: {
        icon: Clock,
        ring: 'bg-warning text-warning-foreground',
        text: 'En revisión',
    },
    pending: {
        icon: Clock,
        ring: 'bg-surface-3 text-muted-foreground',
        text: 'En espera',
    },
    rejected: { icon: X, ring: 'bg-danger text-white', text: 'Rechazado' },
    cancelled: {
        icon: Ban,
        ring: 'bg-neutral-soft text-muted-foreground',
        text: 'Cancelado',
    },
} as const;
</script>

<template>
    <ol class="flex flex-col gap-0 sm:flex-row sm:items-start">
        <li
            v-for="(level, index) in levels"
            :key="level.level"
            class="relative flex flex-1 items-center gap-3 pb-4 sm:flex-col sm:items-center sm:pb-0 sm:text-center"
        >
            <span
                v-if="index < levels.length - 1"
                aria-hidden="true"
                :class="
                    cn(
                        'absolute top-8 bottom-0 left-4 w-px sm:top-4 sm:right-[-50%] sm:bottom-auto sm:left-[50%] sm:h-px sm:w-full',
                        level.state === 'done' ? 'bg-success' : 'bg-border',
                    )
                "
            />
            <span
                :class="
                    cn(
                        'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-4 ring-card',
                        STATE[level.state].ring,
                    )
                "
            >
                <component
                    :is="STATE[level.state].icon"
                    class="size-4"
                    aria-hidden="true"
                />
            </span>
            <div class="min-w-0 sm:mt-2">
                <p class="text-sm font-semibold">{{ level.label }}</p>
                <p class="text-xs text-muted-foreground">
                    Nivel {{ level.level }} · {{ STATE[level.state].text }}
                </p>
            </div>
        </li>
    </ol>
</template>
