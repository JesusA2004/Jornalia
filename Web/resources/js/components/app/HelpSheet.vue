<script setup lang="ts">
import { CircleHelp } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { HelpStep } from '@/types';

/**
 * Ayuda "?" de cada módulo (AGENTS.md regla 12: la interfaz lleva poco
 * texto; las explicaciones viven aquí). Pasos numerados, breves y con ícono.
 */
withDefaults(
    defineProps<{
        steps: HelpStep[];
        title?: string;
        /** Una línea bajo el título. */
        description?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { title: 'Cómo funciona' },
);
</script>

<template>
    <Sheet>
        <SheetTrigger as-child>
            <Button
                variant="outline"
                size="icon"
                :class="
                    cn(
                        'size-8 rounded-full text-muted-foreground hover:text-foreground',
                        $props.class,
                    )
                "
                aria-label="Ayuda"
                title="Ayuda"
            >
                <CircleHelp class="icon-md" />
            </Button>
        </SheetTrigger>
        <SheetContent side="right" class="w-full gap-0 p-0 sm:max-w-md">
            <SheetHeader class="relative border-b px-6 pt-7 pb-5">
                <SheetTitle class="font-display text-xl font-semibold">{{
                    title
                }}</SheetTitle>
                <SheetDescription v-if="description">{{
                    description
                }}</SheetDescription>
            </SheetHeader>
            <ol class="flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <li
                    v-for="(step, index) in steps"
                    :key="index"
                    class="flex gap-4"
                >
                    <span
                        class="relative flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"
                    >
                        <component
                            :is="step.icon"
                            v-if="step.icon"
                            class="icon-md"
                            aria-hidden="true"
                        />
                        <span v-else class="numeric text-sm font-semibold">{{
                            index + 1
                        }}</span>
                        <span
                            v-if="step.icon"
                            class="absolute -top-1.5 -right-1.5 flex size-[1.125rem] items-center justify-center rounded-full bg-gold text-[0.625rem] font-bold text-gold-foreground"
                            >{{ index + 1 }}</span
                        >
                    </span>
                    <div class="min-w-0 space-y-1 pt-0.5">
                        <p class="text-sm font-semibold text-foreground">
                            {{ step.title }}
                        </p>
                        <p
                            v-if="step.text"
                            class="text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ step.text }}
                        </p>
                    </div>
                </li>
            </ol>
            <div v-if="$slots.default" class="border-t px-6 py-5">
                <slot />
            </div>
        </SheetContent>
    </Sheet>
</template>
