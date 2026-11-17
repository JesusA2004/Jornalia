<script setup lang="ts">
import { FileCheck2, Fingerprint, Scale } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { BRAND } from '@/lib/brand';

defineProps<{
    title?: string;
    description?: string;
}>();

const year = new Date().getFullYear();

const principles = [
    { icon: Fingerprint, title: 'Marcajes inalterables' },
    { icon: FileCheck2, title: 'Trazabilidad completa' },
    { icon: Scale, title: 'Conforme a la LFT' },
];
</script>

<template>
    <div
        class="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]"
    >
        <!-- Panel de marca -->
        <aside
            class="relative isolate hidden overflow-hidden bg-brand-panel text-brand-panel-foreground lg:flex lg:flex-col"
        >
            <div
                class="pointer-events-none absolute inset-0 -z-10 pattern-grid [mask-image:radial-gradient(ellipse_at_20%_30%,black_10%,transparent_70%)] text-white"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -right-32 -bottom-40 -z-10 size-[34rem] rounded-full bg-brand-gradient opacity-25 blur-3xl"
                aria-hidden="true"
            />
            <span
                class="absolute inset-x-0 top-0 h-1.5 bg-brand-gradient"
                aria-hidden="true"
            />

            <div class="flex flex-1 flex-col p-10 xl:p-14">
                <AppLogo size="lg" inverted />

                <div
                    class="relative z-10 mt-auto max-w-[min(24rem,52%)] space-y-9 pb-6"
                >
                    <div class="space-y-4">
                        <h2
                            class="font-display text-[2.5rem] leading-[1.05] font-bold text-balance xl:text-[2.75rem]"
                        >
                            Asistencia confiable, trazable y
                            <span class="text-brand-gradient">en regla.</span>
                        </h2>
                    </div>

                    <ul class="space-y-4">
                        <li
                            v-for="item in principles"
                            :key="item.title"
                            class="flex items-center gap-3.5"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-white/[0.08] text-brand ring-1 ring-white/10"
                            >
                                <component
                                    :is="item.icon"
                                    class="icon-sm"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="space-y-0.5">
                                <span class="block text-sm font-semibold">{{
                                    item.title
                                }}</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <p class="text-xs text-white/45">
                    © {{ year }} {{ BRAND.name }} · Acceso exclusivo para
                    personal autorizado.
                </p>
            </div>

            <img
                :src="BRAND.mascotGreetingUrl"
                alt=""
                aria-hidden="true"
                class="pointer-events-none absolute right-4 bottom-0 h-[min(44%,24rem)] w-auto drop-shadow-2xl select-none xl:right-10"
                draggable="false"
            />
        </aside>

        <!-- Formulario -->
        <main class="relative flex flex-col">
            <span
                class="absolute inset-x-0 top-0 h-1 bg-brand-gradient lg:hidden"
                aria-hidden="true"
            />
            <div
                class="flex items-center justify-center px-6 pt-10 pb-2 lg:hidden"
            >
                <AppLogo size="lg" :with-product="false" />
            </div>
            <div
                class="flex flex-1 items-start justify-center px-5 pt-6 pb-10 sm:items-center sm:px-10 lg:py-12"
            >
                <div class="w-full max-w-[26rem] space-y-7">
                    <div class="space-y-2 text-center lg:text-left">
                        <p
                            class="text-xs font-semibold tracking-[0.16em] text-brand-strong uppercase lg:hidden"
                        >
                            {{ BRAND.product }}
                        </p>
                        <h1
                            v-if="title"
                            class="font-display text-[1.875rem] leading-tight font-bold text-foreground"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
            <p
                class="px-6 pb-6 text-center text-xs text-subtle-foreground lg:hidden"
            >
                © {{ year }} {{ BRAND.name }} · Acceso exclusivo para personal
                autorizado.
            </p>
        </main>
    </div>
</template>
