<script setup lang="ts">
import { Check, Send, X } from '@lucide/vue';
import { computed } from 'vue';
import Timeline from '@/components/app/Timeline.vue';
import type { TimelineItem } from '@/types';
import type { DecisionRow } from '@/types/operacion';

/** Historial de la solicitud: alta + decisiones por nivel. */
const props = defineProps<{
    decisions: DecisionRow[];
    createdAt: string;
    requestedBy: string | null;
    timezone?: string;
}>();

const items = computed<TimelineItem[]>(() => [
    {
        id: 'created',
        title: 'Solicitud registrada',
        at: props.createdAt,
        timezone: props.timezone,
        actor: props.requestedBy,
        tone: 'info',
        icon: Send,
    },
    ...props.decisions.map((d) => ({
        id: d.id,
        title: `${d.decision_label} · nivel ${d.level}`,
        description: d.comment,
        at: d.decided_at,
        timezone: props.timezone,
        actor: d.decided_by,
        tone:
            d.decision === 'approved'
                ? ('success' as const)
                : ('danger' as const),
        icon: d.decision === 'approved' ? Check : X,
    })),
]);
</script>

<template>
    <Timeline :items="items" />
</template>
