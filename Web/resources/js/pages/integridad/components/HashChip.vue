<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * Huella (hash / identificador) abreviada en monoespaciada con copiado.
 * Componente local de Operación (integridad, reportes).
 */
const props = withDefaults(
    defineProps<{
        value: string | null | undefined;
        /** Caracteres visibles al inicio y al final. */
        head?: number;
        tail?: number;
        label?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { head: 10, tail: 6, label: 'Copiar' },
);

// `legacy` evita depender del API del navegador (y el desajuste de hidratación SSR).
const { copy, copied } = useClipboard({ copiedDuring: 1600, legacy: true });

const short = computed(() => {
    const v = props.value ?? '';
    if (v.length <= props.head + props.tail + 1) {
        return v;
    }

    return `${v.slice(0, props.head)}…${v.slice(-props.tail)}`;
});
</script>

<template>
    <span
        v-if="!value"
        :class="cn('text-sm text-muted-foreground', props.class)"
        >—</span
    >
    <TooltipProvider v-else :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :class="
                        cn(
                            'group/hash inline-flex max-w-full items-center gap-1.5 rounded-md border border-border bg-surface-2 px-2 py-0.5 font-mono text-[0.75rem] text-foreground/90 transition-colors hover:border-border-strong hover:bg-surface-3 focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none',
                            props.class,
                        )
                    "
                    :aria-label="`${label}: ${value}`"
                    @click.stop="copy(value)"
                >
                    <span class="truncate">{{ short }}</span>
                    <Check
                        v-if="copied"
                        class="size-3.5 shrink-0 text-success-soft-foreground"
                        aria-hidden="true"
                    />
                    <Copy
                        v-else
                        class="size-3.5 shrink-0 text-muted-foreground group-hover/hash:text-foreground"
                        aria-hidden="true"
                    />
                </button>
            </TooltipTrigger>
            <TooltipContent
                class="max-w-sm font-mono text-[0.6875rem] break-all"
            >
                {{ copied ? 'Copiado' : value }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
