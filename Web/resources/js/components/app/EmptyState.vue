<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { BRAND } from '@/lib/brand';
import { TONE_ICON_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Tone } from '@/types';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: LucideIcon;
        tone?: Tone;
        size?: 'sm' | 'md';
        /** Borde discontinuo (para usar dentro de un área vacía). */
        bordered?: boolean;
        /**
         * Muestra el personaje MR LANA en lugar del ícono. Úsalo con
         * moderación (estados vacíos principales, no en cada tabla).
         */
        mascot?: 'saludo' | 'presenta' | false;
        class?: HTMLAttributes['class'];
    }>(),
    {
        icon: () => Inbox,
        tone: 'neutral',
        size: 'md',
        bordered: false,
        mascot: false,
    },
);
</script>

<template>
    <Empty
        :class="
            cn(
                'gap-4',
                size === 'sm' ? 'p-6 md:p-8' : 'p-8 md:p-14',
                bordered &&
                    'rounded-2xl border border-dashed border-border-strong bg-surface-2/40',
                $props.class,
            )
        "
    >
        <EmptyHeader>
            <img
                v-if="mascot"
                :src="
                    mascot === 'saludo'
                        ? BRAND.mascotGreetingUrl
                        : BRAND.mascotPresentingUrl
                "
                alt=""
                aria-hidden="true"
                draggable="false"
                :class="
                    cn(
                        'mb-2 w-auto select-none',
                        size === 'sm' ? 'h-24' : 'h-36',
                    )
                "
            />
            <EmptyMedia
                v-else
                :class="
                    cn(
                        'relative mb-3 flex items-center justify-center rounded-xl ring-1 ring-border',
                        size === 'sm' ? 'size-10' : 'size-12',
                        TONE_ICON_CLASSES[tone],
                    )
                "
            >
                <component
                    :is="icon"
                    :class="size === 'sm' ? 'icon-md' : 'icon-lg'"
                    aria-hidden="true"
                />
            </EmptyMedia>
            <EmptyTitle class="font-display text-lg font-bold">{{
                title
            }}</EmptyTitle>
            <EmptyDescription v-if="description || $slots.description">
                <slot name="description">{{ description }}</slot>
            </EmptyDescription>
        </EmptyHeader>
        <EmptyContent v-if="$slots.default || $slots.actions">
            <div class="flex flex-wrap items-center justify-center gap-2">
                <slot name="actions" />
                <slot />
            </div>
        </EmptyContent>
    </Empty>
</template>
