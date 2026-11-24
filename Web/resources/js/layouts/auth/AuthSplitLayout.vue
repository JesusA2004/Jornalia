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
    { icon: FileCheck2, title: 'Correcciones con firma del colaborador' },
    { icon: Scale, title: 'Parámetros de la LFT por año' },
];
</script>

<template>
    <div
        class="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]"
    >
        <!-- Panel institucional (sin imágenes ni decoración) -->
        <aside
            class="hidden bg-brand-panel text-brand-panel-foreground lg:flex lg:flex-col"
        >
            <div class="flex flex-1 flex-col p-10 xl:p-14">
                <AppLogo size="md" inverted />

                <div class="mt-auto max-w-md space-y-8 pb-4">
                    <div class="space-y-5">
                        <span
                            class="block h-0.5 w-10 rounded-full bg-[var(--sidebar-gold)]"
                            aria-hidden="true"
                        />
                        <h2
                            class="text-[2rem] leading-[1.15] font-semibold text-balance xl:text-[2.25rem]"
                        >
                            Registro electrónico de jornada.
                        </h2>
                    </div>

                    <ul class="space-y-3.5">
                        <li
                            v-for="item in principles"
                            :key="item.title"
                            class="flex items-center gap-3 text-sm text-white/80"
                        >
                            <component
                                :is="item.icon"
                                class="icon-sm shrink-0 text-[var(--sidebar-gold)]"
                                aria-hidden="true"
                            />
                            {{ item.title }}
                        </li>
                    </ul>
                </div>

                <p class="mt-10 text-xs text-white/50">
                    © {{ year }} {{ BRAND.name }} · Acceso exclusivo para
                    personal autorizado.
                </p>
            </div>
        </aside>

        <!-- Formulario -->
        <main class="flex flex-col">
            <div
                class="flex items-center justify-center bg-brand-panel px-6 py-5 lg:hidden"
            >
                <AppLogo size="sm" inverted />
            </div>
            <div
                class="flex flex-1 items-start justify-center px-4 pt-8 pb-10 sm:items-center sm:px-10 lg:py-12"
            >
                <div class="w-full max-w-[24rem] space-y-7">
                    <div class="space-y-1.5">
                        <h1
                            v-if="title"
                            class="font-display text-[1.625rem] leading-tight font-semibold text-foreground"
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
