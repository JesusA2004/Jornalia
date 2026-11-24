<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { RectificationTab } from '@/types/operacion';
import { visitWith } from './shared';
import { RECTIFICATION_TABS } from './stages';

/** Pestañas por etapa del flujo con su conteo. */
const props = defineProps<{
    tab: RectificationTab;
    counts: Record<RectificationTab, number>;
}>();

const ACTIONABLE: RectificationTab[] = [
    'por_autorizar',
    'por_firmar',
    'por_validar',
];

function go(key: RectificationTab): void {
    if (key !== props.tab) {
        visitWith({ tab: key });
    }
}
</script>

<template>
    <nav
        aria-label="Etapas"
        class="inline-flex w-full items-center gap-1 overflow-x-auto rounded-xl border bg-surface-2 p-1"
    >
        <button
            v-for="item in RECTIFICATION_TABS"
            :key="item.key"
            type="button"
            :aria-current="item.key === tab ? 'page' : undefined"
            :class="
                cn(
                    'inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-lg px-3 text-sm font-medium whitespace-nowrap transition-colors',
                    item.key === tab
                        ? 'bg-card text-foreground shadow-elev-1 ring-1 ring-border'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="go(item.key)"
        >
            {{ item.label }}
            <span
                v-if="ACTIONABLE.includes(item.key)"
                :class="
                    cn(
                        'rounded-full px-1.5 numeric text-[0.6875rem] font-semibold',
                        counts[item.key] > 0
                            ? 'bg-warning-soft text-warning-soft-foreground'
                            : 'bg-neutral-soft text-neutral-soft-foreground',
                    )
                "
                >{{ counts[item.key] }}</span
            >
        </button>
    </nav>
</template>
