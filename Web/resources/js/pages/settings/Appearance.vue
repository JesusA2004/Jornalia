<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Monitor, Moon, Palette, Sun } from '@lucide/vue';
import SectionCard from '@/components/app/SectionCard.vue';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useAppearance } from '@/composables/useAppearance';
import { cn } from '@/lib/utils';
import type { Appearance } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi cuenta', href: '/settings/profile' },
            { title: 'Apariencia', href: '/settings/appearance' },
        ],
    },
});

const { appearance, updateAppearance } = useAppearance();

const options = [
    {
        value: 'light',
        label: 'Claro',
        description: 'Fondo blanco.',
        icon: Sun,
    },
    {
        value: 'dark',
        label: 'Oscuro',
        description: 'Menos brillo.',
        icon: Moon,
    },
    {
        value: 'system',
        label: 'Sistema',
        description: 'Como tu equipo.',
        icon: Monitor,
    },
] as const;

function onChange(value: unknown): void {
    updateAppearance(value as Appearance);
}
</script>

<template>
    <Head title="Apariencia" />

    <SectionCard
        title="Tema de la interfaz"
        description="Se guarda en este navegador."
        :icon="Palette"
        divided
    >
        <RadioGroup
            :model-value="appearance"
            class="grid gap-4 sm:grid-cols-3"
            aria-label="Tema de la interfaz"
            @update:model-value="onChange"
        >
            <label
                v-for="option in options"
                :key="option.value"
                :class="
                    cn(
                        'group relative flex cursor-pointer flex-col overflow-hidden rounded-xl border bg-card transition-standard hover:border-border-strong',
                        appearance === option.value &&
                            'border-primary/60 ring-[3px] ring-primary/15 hover:border-primary/60',
                    )
                "
            >
                <!-- Vista previa en miniatura -->
                <div
                    :class="
                        cn(
                            'relative flex h-28 gap-2 overflow-hidden border-b p-3',
                            option.value === 'light' && 'bg-[#12302b]',
                            option.value === 'dark' && 'bg-[#0a1a17]',
                            option.value === 'system' &&
                                'bg-[linear-gradient(105deg,#12302b_50%,#0a1a17_50%)]',
                        )
                    "
                    aria-hidden="true"
                >
                    <div class="flex w-8 flex-col gap-1.5 pt-1">
                        <span class="h-1.5 w-6 rounded-full bg-[#a0e04e]" />
                        <span
                            :class="
                                cn(
                                    'h-1.5 w-5 rounded-full',
                                    option.value === 'dark'
                                        ? 'bg-white/20'
                                        : 'bg-white/25',
                                )
                            "
                        />
                        <span
                            :class="
                                cn(
                                    'h-1.5 w-6 rounded-full',
                                    option.value === 'dark'
                                        ? 'bg-white/20'
                                        : 'bg-white/25',
                                )
                            "
                        />
                    </div>
                    <div
                        :class="
                            cn(
                                'flex flex-1 flex-col gap-2 rounded-md p-2 shadow-sm',
                                option.value === 'dark'
                                    ? 'bg-[#13201d]'
                                    : 'bg-white',
                            )
                        "
                    >
                        <span
                            :class="
                                cn(
                                    'h-2 w-2/3 rounded-full',
                                    option.value === 'dark'
                                        ? 'bg-white/30'
                                        : 'bg-black/20',
                                )
                            "
                        />
                        <div class="flex gap-1.5">
                            <span
                                v-for="n in 3"
                                :key="n"
                                :class="
                                    cn(
                                        'h-8 flex-1 rounded',
                                        option.value === 'dark'
                                            ? 'bg-white/10'
                                            : 'bg-black/5',
                                    )
                                "
                            />
                        </div>
                        <span
                            class="h-1.5 w-1/3 rounded-full bg-[linear-gradient(90deg,#a0e04e,#82dbe8)]"
                        />
                    </div>
                </div>
                <div class="flex items-start gap-3 p-4">
                    <RadioGroupItem :value="option.value" class="mt-0.5" />
                    <div class="grid gap-0.5">
                        <span
                            class="flex items-center gap-1.5 text-sm font-medium"
                        >
                            <component
                                :is="option.icon"
                                class="icon-xs"
                                aria-hidden="true"
                            />
                            {{ option.label }}
                        </span>
                        <span class="text-xs text-muted-foreground">{{
                            option.description
                        }}</span>
                    </div>
                </div>
            </label>
        </RadioGroup>
    </SectionCard>
</template>
