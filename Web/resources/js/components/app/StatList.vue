<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { TONE_DOT_CLASSES, TONE_ICON_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { StatListItem } from '@/types';

withDefaults(
    defineProps<{
        items: StatListItem[];
        /** Separadores entre filas. */
        divided?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { divided: true },
);
</script>

<template>
    <ul :class="cn('flex flex-col', divided && 'divide-y', $props.class)">
        <li
            v-for="item in items"
            :key="item.key"
            class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0"
        >
            <div class="flex items-center gap-3">
                <span
                    v-if="item.icon"
                    :class="
                        cn(
                            'flex size-8 shrink-0 items-center justify-center rounded-lg',
                            TONE_ICON_CLASSES[item.tone ?? 'neutral'],
                        )
                    "
                >
                    <component
                        :is="item.icon"
                        class="icon-sm"
                        aria-hidden="true"
                    />
                </span>
                <span
                    v-else-if="item.tone"
                    :class="
                        cn(
                            'size-2 shrink-0 rounded-full',
                            TONE_DOT_CLASSES[item.tone],
                        )
                    "
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm text-foreground">
                        <slot name="label" :item="item">{{ item.label }}</slot>
                    </p>
                    <p
                        v-if="item.hint"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ item.hint }}
                    </p>
                </div>
                <span class="numeric text-sm font-semibold text-foreground">
                    <slot name="value" :item="item">{{ item.value }}</slot>
                </span>
            </div>
            <div
                v-if="item.progress !== undefined"
                class="h-1.5 w-full overflow-hidden rounded-full bg-surface-3"
                role="progressbar"
                :aria-valuenow="Math.round(item.progress)"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="item.label"
            >
                <div
                    :class="
                        cn(
                            'h-full rounded-full transition-[width] duration-500 ease-standard',
                            TONE_DOT_CLASSES[item.tone ?? 'primary'],
                        )
                    "
                    :style="{
                        width: `${Math.min(100, Math.max(0, item.progress))}%`,
                    }"
                />
            </div>
        </li>
    </ul>
</template>
