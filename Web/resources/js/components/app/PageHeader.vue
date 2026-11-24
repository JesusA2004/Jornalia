<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import HelpSheet from '@/components/app/HelpSheet.vue';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, HelpStep } from '@/types';

withDefaults(
    defineProps<{
        title: string;
        /** UNA línea corta. Las explicaciones van en `help`. */
        description?: string;
        /** Pasos de la ayuda "?" (abre <HelpSheet>). También hay slot `help`. */
        help?: HelpStep[];
        /** Título del panel de ayuda. */
        helpTitle?: string;
        /** Texto pequeño sobre el título (módulo, folio…). */
        eyebrow?: string;
        icon?: LucideIcon;
        /**
         * Migas en línea (opcional). Las migas del encabezado global se
         * definen con `defineOptions({ layout: { breadcrumbs } })`.
         */
        breadcrumbs?: BreadcrumbItem[];
        /** Enlace "Volver". */
        backHref?: NonNullable<InertiaLinkProps['href']>;
        backLabel?: string;
        /** Establece también el <title> del documento. Default true. */
        setTitle?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { breadcrumbs: () => [], backLabel: 'Volver', setTitle: true },
);
</script>

<template>
    <Head v-if="setTitle" :title="title" />
    <header
        data-slot="page-header"
        :class="cn('flex flex-col gap-4 pb-2', $props.class)"
    >
        <nav
            v-if="breadcrumbs.length > 0 || backHref"
            aria-label="Ruta de navegación"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Link
                v-if="backHref"
                :href="backHref"
                class="-ml-1 inline-flex items-center gap-1 rounded-md px-1 py-0.5 font-medium transition-standard hover:text-foreground"
            >
                <ArrowLeft class="icon-xs" aria-hidden="true" />
                {{ backLabel }}
            </Link>
            <template v-for="(crumb, index) in breadcrumbs" :key="index">
                <ChevronRight
                    v-if="index > 0 || backHref"
                    class="icon-xs opacity-50"
                    aria-hidden="true"
                />
                <Link
                    v-if="crumb.href && index < breadcrumbs.length - 1"
                    :href="crumb.href"
                    class="transition-standard hover:text-foreground"
                    >{{ crumb.title }}</Link
                >
                <span v-else class="text-foreground/80">{{ crumb.title }}</span>
            </template>
        </nav>

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="flex min-w-0 items-start gap-4">
                <div
                    v-if="icon"
                    class="hidden size-11 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground sm:flex"
                >
                    <component :is="icon" class="icon-lg" aria-hidden="true" />
                </div>
                <div class="min-w-0 space-y-1">
                    <p
                        v-if="eyebrow"
                        class="text-[0.6875rem] font-semibold tracking-[0.18em] text-gold-strong uppercase"
                    >
                        {{ eyebrow }}
                    </p>
                    <div class="flex items-center gap-2.5">
                        <h1
                            class="font-display text-[1.5rem] leading-tight font-semibold text-balance text-foreground md:text-[1.75rem]"
                        >
                            {{ title }}
                        </h1>
                        <slot name="help">
                            <HelpSheet
                                v-if="help && help.length > 0"
                                :steps="help"
                                :title="helpTitle ?? title"
                                class="shrink-0"
                            />
                        </slot>
                    </div>
                    <p
                        v-if="description"
                        class="line-clamp-2 max-w-2xl text-sm text-muted-foreground sm:line-clamp-1"
                    >
                        {{ description }}
                    </p>
                    <div
                        v-if="$slots.meta"
                        class="flex flex-wrap items-center gap-2 pt-1"
                    >
                        <slot name="meta" />
                    </div>
                </div>
            </div>
            <div
                v-if="$slots.actions"
                class="flex shrink-0 flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </div>
        <slot />
    </header>
</template>
