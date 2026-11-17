<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        icon?: LucideIcon;
        /** Relleno del cuerpo. `none` para tablas/listas a sangre. */
        padding?: 'none' | 'sm' | 'md';
        variant?: 'default' | 'muted' | 'outline';
        /** Separador entre encabezado y cuerpo. */
        divided?: boolean;
        class?: HTMLAttributes['class'];
        bodyClass?: HTMLAttributes['class'];
    }>(),
    { padding: 'md', variant: 'default', divided: false },
);
</script>

<template>
    <section
        data-slot="section-card"
        :class="
            cn(
                'flex min-w-0 flex-col rounded-2xl border text-card-foreground',
                variant === 'default' && 'bg-card shadow-elev-1',
                variant === 'muted' && 'bg-surface-2',
                variant === 'outline' && 'bg-transparent',
                $props.class,
            )
        "
    >
        <header
            v-if="title || $slots.header || $slots.actions"
            :class="
                cn(
                    'flex flex-wrap items-start gap-x-4 gap-y-2 px-5 pt-5',
                    divided ? 'border-b pb-4' : 'pb-1',
                )
            "
        >
            <slot name="header">
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <div
                        v-if="icon"
                        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-surface-3 text-muted-foreground ring-1 ring-border"
                    >
                        <component
                            :is="icon"
                            class="icon-sm"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0 space-y-1">
                        <h2
                            class="font-display text-lg leading-tight font-bold"
                        >
                            {{ title }}
                        </h2>
                        <p
                            v-if="description"
                            class="text-sm leading-snug text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                </div>
            </slot>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div
            :class="
                cn(
                    'min-w-0 flex-1',
                    padding === 'md' && 'p-5',
                    padding === 'sm' && 'p-3',
                    bodyClass,
                )
            "
        >
            <slot />
        </div>
        <footer
            v-if="$slots.footer"
            class="flex flex-wrap items-center gap-2 border-t bg-surface-2/60 px-5 py-3 text-sm text-muted-foreground"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
