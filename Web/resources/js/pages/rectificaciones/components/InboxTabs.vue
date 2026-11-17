<script setup lang="ts">
import { CheckCheck, Inbox, Layers } from '@lucide/vue';
import { cn } from '@/lib/utils';
import type { InboxCounts, InboxTab } from '@/types/operacion';
import { visitWith } from './shared';

/** Pestañas de bandeja (Pendientes / Resueltas / Todas) con contadores. */
const props = defineProps<{ tab: InboxTab; counts: InboxCounts }>();

const tabs = [
    {
        key: 'pendientes' as const,
        label: 'Pendientes',
        icon: Inbox,
        count: () => props.counts.pending,
    },
    {
        key: 'resueltas' as const,
        label: 'Resueltas',
        icon: CheckCheck,
        count: () => props.counts.resolved,
    },
    {
        key: 'todas' as const,
        label: 'Todas',
        icon: Layers,
        count: () => props.counts.all,
    },
];

function go(key: InboxTab): void {
    if (key !== props.tab) {
        visitWith({ tab: key === 'pendientes' ? null : key, status: null });
    }
}
</script>

<template>
    <nav
        aria-label="Bandeja"
        class="inline-flex w-full items-center gap-1 overflow-x-auto rounded-xl border bg-surface-2 p-1 sm:w-auto"
    >
        <button
            v-for="item in tabs"
            :key="item.key"
            type="button"
            :aria-current="item.key === tab ? 'page' : undefined"
            :class="
                cn(
                    'inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-lg px-3.5 text-sm font-medium whitespace-nowrap transition-colors sm:flex-none',
                    item.key === tab
                        ? 'bg-card text-foreground shadow-elev-1 ring-1 ring-border'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="go(item.key)"
        >
            <component :is="item.icon" class="size-4" aria-hidden="true" />
            {{ item.label }}
            <span
                :class="
                    cn(
                        'rounded-full px-1.5 numeric text-[0.6875rem] font-semibold',
                        item.key === 'pendientes' && item.count() > 0
                            ? 'bg-warning-soft text-warning-soft-foreground'
                            : 'bg-neutral-soft text-neutral-soft-foreground',
                    )
                "
                >{{ item.count() }}</span
            >
        </button>
    </nav>
</template>
