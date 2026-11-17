<script setup lang="ts">
import { CheckCheck, Inbox, TriangleAlert } from '@lucide/vue';
import { cn } from '@/lib/utils';
import type { AnnouncementStats } from '../types';

defineProps<{ stats: AnnouncementStats; compact?: boolean }>();
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-wrap items-center gap-x-3 gap-y-1 numeric text-sm',
                compact && 'text-xs',
            )
        "
    >
        <span
            class="inline-flex items-center gap-1"
            :title="`Entregados ${stats.delivered} de ${stats.total}`"
        >
            <Inbox class="icon-xs text-muted-foreground" aria-hidden="true" />
            {{ stats.delivered }}/{{ stats.total }}
        </span>
        <span
            class="inline-flex items-center gap-1 text-success"
            :title="`Leídos ${stats.read}`"
        >
            <CheckCheck class="icon-xs" aria-hidden="true" />
            {{ stats.read }}
        </span>
        <span
            v-if="stats.failed > 0"
            class="inline-flex items-center gap-1 text-danger"
            :title="`Fallidos ${stats.failed}`"
        >
            <TriangleAlert class="icon-xs" aria-hidden="true" />
            {{ stats.failed }}
        </span>
    </div>
</template>
