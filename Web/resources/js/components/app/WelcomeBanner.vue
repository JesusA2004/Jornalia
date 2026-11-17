<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { BRAND } from '@/lib/brand';
import { cn } from '@/lib/utils';

/**
 * Banda de bienvenida con el degradado oficial y el personaje MR LANA.
 * Úsala UNA vez (p. ej. en el Panel). Texto siempre en negro sobre el degradado.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        /** Texto pequeño superior (fecha, empresa…). */
        eyebrow?: string;
        mascot?: 'saludo' | 'presenta' | false;
        class?: HTMLAttributes['class'];
    }>(),
    { mascot: 'saludo' },
);
</script>

<template>
    <section
        :class="
            cn(
                'relative isolate overflow-hidden rounded-2xl bg-brand-gradient text-black shadow-elev-1',
                $props.class,
            )
        "
    >
        <div
            class="pointer-events-none absolute inset-0 -z-10 pattern-grid [mask-image:linear-gradient(90deg,transparent,black_70%)] text-black/40"
            aria-hidden="true"
        />
        <div
            :class="
                cn(
                    'relative flex min-h-36 flex-col justify-center gap-3 p-6 sm:p-8',
                    mascot && 'sm:pr-56',
                )
            "
        >
            <p
                v-if="eyebrow"
                class="text-xs font-bold tracking-[0.14em] text-black/65 uppercase"
            >
                {{ eyebrow }}
            </p>
            <h2
                class="font-display text-2xl leading-tight font-bold text-balance sm:text-[2rem]"
            >
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="max-w-xl text-sm leading-relaxed font-medium text-black/75"
            >
                {{ description }}
            </p>
            <div v-if="$slots.default" class="flex flex-wrap gap-2 pt-1">
                <slot />
            </div>
        </div>
        <img
            v-if="mascot"
            :src="
                mascot === 'saludo'
                    ? BRAND.mascotGreetingUrl
                    : BRAND.mascotPresentingUrl
            "
            alt=""
            aria-hidden="true"
            draggable="false"
            class="pointer-events-none absolute right-6 -bottom-3 hidden h-[115%] max-h-60 w-auto select-none sm:block"
        />
    </section>
</template>
