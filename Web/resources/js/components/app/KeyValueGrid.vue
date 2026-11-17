<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import type { KeyValueItem } from '@/types';

withDefaults(
    defineProps<{
        items: KeyValueItem[];
        columns?: 1 | 2 | 3;
        /** Texto para valores vacíos. */
        fallback?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { columns: 2, fallback: '—' },
);
</script>

<template>
    <dl
        :class="
            cn(
                'grid gap-x-8 gap-y-5',
                columns === 2 && 'sm:grid-cols-2',
                columns === 3 && 'sm:grid-cols-2 lg:grid-cols-3',
                $props.class,
            )
        "
    >
        <div
            v-for="item in items"
            :key="item.key"
            :class="cn('min-w-0 space-y-1', item.full && 'sm:col-span-full')"
        >
            <dt class="text-xs font-medium text-muted-foreground">
                {{ item.label }}
            </dt>
            <dd
                :class="
                    cn(
                        'text-sm break-words text-foreground',
                        item.mono && 'font-mono text-[0.8125rem]',
                    )
                "
            >
                <slot :name="`value-${item.key}`" :item="item">
                    <span
                        v-if="
                            item.value === null ||
                            item.value === undefined ||
                            item.value === ''
                        "
                        class="text-subtle-foreground"
                        >{{ fallback }}</span
                    >
                    <template v-else>{{ item.value }}</template>
                </slot>
                <p
                    v-if="item.hint"
                    class="mt-0.5 text-xs text-muted-foreground"
                >
                    {{ item.hint }}
                </p>
            </dd>
        </div>
    </dl>
</template>
