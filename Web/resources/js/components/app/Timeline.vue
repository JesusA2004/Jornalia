<script setup lang="ts">
import { Circle } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import { TONE_ICON_CLASSES } from '@/lib/status';
import type { DateTimeFormat } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TimelineItem } from '@/types';

withDefaults(
    defineProps<{
        items: TimelineItem[];
        dateFormat?: DateTimeFormat;
        /** Texto si no hay elementos. */
        emptyText?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { dateFormat: 'datetime', emptyText: 'Sin movimientos registrados.' },
);
</script>

<template>
    <p
        v-if="items.length === 0"
        class="py-6 text-center text-sm text-muted-foreground"
    >
        {{ emptyText }}
    </p>
    <ol v-else :class="cn('relative flex flex-col', $props.class)">
        <li
            v-for="(item, index) in items"
            :key="item.id"
            class="relative flex gap-4 pb-6 last:pb-0"
        >
            <span
                v-if="index < items.length - 1"
                class="absolute top-9 bottom-1 left-[15px] w-px bg-border"
                aria-hidden="true"
            />
            <span
                :class="
                    cn(
                        'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-4 ring-card',
                        TONE_ICON_CLASSES[item.tone ?? 'neutral'],
                    )
                "
            >
                <component
                    :is="item.icon ?? Circle"
                    :class="item.icon ? 'icon-xs' : 'size-2 fill-current'"
                    aria-hidden="true"
                />
            </span>
            <div class="min-w-0 flex-1 pt-1">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5"
                >
                    <p class="text-sm font-medium text-foreground">
                        <slot name="title" :item="item">{{ item.title }}</slot>
                    </p>
                    <DateTimeDisplay
                        v-if="item.at"
                        :value="item.at"
                        :timezone="item.timezone"
                        :format="dateFormat"
                        class="text-xs text-muted-foreground"
                    />
                </div>
                <p
                    v-if="item.description"
                    class="mt-1 text-sm leading-relaxed text-muted-foreground"
                >
                    {{ item.description }}
                </p>
                <p
                    v-if="item.actor || item.meta"
                    class="mt-1.5 flex flex-wrap items-center gap-x-2 text-xs text-subtle-foreground"
                >
                    <span
                        v-if="item.actor"
                        class="font-medium text-muted-foreground"
                        >{{ item.actor }}</span
                    >
                    <span v-if="item.actor && item.meta" aria-hidden="true"
                        >·</span
                    >
                    <span v-if="item.meta">{{ item.meta }}</span>
                </p>
                <slot name="content" :item="item" />
            </div>
        </li>
    </ol>
</template>
