<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { KeyRound, Palette, UserRound } from '@lucide/vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';

const items = [
    {
        title: 'Perfil',
        description: 'Nombre y correo',
        href: '/settings/profile',
        icon: UserRound,
    },
    {
        title: 'Seguridad',
        description: 'Contraseña de acceso',
        href: '/settings/security',
        icon: KeyRound,
    },
    {
        title: 'Apariencia',
        description: 'Tema de la interfaz',
        href: '/settings/appearance',
        icon: Palette,
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <PageHeader
        title="Mi cuenta"
        description="Tus datos, acceso y preferencias."
        :set-title="false"
    />

    <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
        <nav
            aria-label="Secciones de la cuenta"
            class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 lg:sticky lg:top-20 lg:w-60 lg:shrink-0 lg:flex-col lg:self-start lg:overflow-visible lg:pb-0"
        >
            <Link
                v-for="item in items"
                :key="item.href"
                :href="item.href"
                :aria-current="
                    isCurrentOrParentUrl(item.href) ? 'page' : undefined
                "
                :class="
                    cn(
                        'group flex shrink-0 items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-standard',
                        isCurrentOrParentUrl(item.href)
                            ? 'bg-card font-medium text-foreground shadow-elev-1 ring-1 ring-border'
                            : 'text-muted-foreground hover:bg-surface-2 hover:text-foreground',
                    )
                "
            >
                <span
                    :class="
                        cn(
                            'flex size-8 shrink-0 items-center justify-center rounded-md transition-standard',
                            isCurrentOrParentUrl(item.href)
                                ? 'bg-primary-soft text-primary-soft-foreground'
                                : 'bg-surface-3 text-muted-foreground',
                        )
                    "
                >
                    <component
                        :is="item.icon"
                        class="icon-sm"
                        aria-hidden="true"
                    />
                </span>
                <span class="grid">
                    <span>{{ item.title }}</span>
                    <span
                        class="hidden text-xs font-normal text-muted-foreground lg:block"
                        >{{ item.description }}</span
                    >
                </span>
            </Link>
        </nav>

        <div class="min-w-0 flex-1 lg:max-w-3xl">
            <div class="flex flex-col gap-6">
                <slot />
            </div>
        </div>
    </div>
</template>
