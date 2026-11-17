<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CornerDownLeft,
    KeyRound,
    Monitor,
    Moon,
    Palette,
    Sun,
    UserRound,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { Kbd } from '@/components/ui/kbd';
import { useAppearance } from '@/composables/useAppearance';
import { useCommandMenu } from '@/composables/useCommandMenu';
import { useNavigation } from '@/composables/useNavigation';
import { toUrl } from '@/lib/utils';
import type { Appearance } from '@/types';

const { open } = useCommandMenu();
const { groups } = useNavigation();
const { updateAppearance } = useAppearance();

function go(href: string): void {
    open.value = false;
    router.visit(href);
}

function setTheme(value: Appearance): void {
    updateAppearance(value);
    open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const accountItems = [
    { title: 'Perfil', href: '/settings/profile', icon: UserRound },
    {
        title: 'Seguridad y contraseña',
        href: '/settings/security',
        icon: KeyRound,
    },
    { title: 'Apariencia', href: '/settings/appearance', icon: Palette },
];

const themeItems = [
    { title: 'Tema claro', value: 'light' as const, icon: Sun },
    { title: 'Tema oscuro', value: 'dark' as const, icon: Moon },
    { title: 'Tema del sistema', value: 'system' as const, icon: Monitor },
];
</script>

<template>
    <CommandDialog
        v-model:open="open"
        title="Buscador"
        description="Busca módulos y acciones"
    >
        <CommandInput
            placeholder="Buscar módulos, acciones o ajustes…"
            class="h-12"
        />
        <CommandList class="max-h-[min(60vh,26rem)]">
            <CommandEmpty
                class="py-10 text-center text-sm text-muted-foreground"
            >
                Sin resultados para tu búsqueda.
            </CommandEmpty>

            <template v-for="group in groups" :key="group.title">
                <CommandGroup :heading="group.title">
                    <CommandItem
                        v-for="item in group.items"
                        :key="toUrl(item.href)"
                        :value="toUrl(item.href)"
                        class="gap-3 py-2"
                        @select="go(toUrl(item.href))"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-md border bg-card text-muted-foreground"
                        >
                            <component
                                :is="item.icon"
                                class="icon-xs"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="grid min-w-0">
                            <span class="truncate font-medium">{{
                                item.title
                            }}</span>
                            <span
                                v-if="item.description"
                                class="truncate text-xs text-muted-foreground"
                                >{{ item.description }}</span
                            >
                        </span>
                        <span class="hidden">{{
                            item.keywords?.join(' ')
                        }}</span>
                    </CommandItem>
                </CommandGroup>
            </template>

            <CommandSeparator />
            <CommandGroup heading="Mi cuenta">
                <CommandItem
                    v-for="item in accountItems"
                    :key="item.href"
                    :value="item.href"
                    class="gap-3"
                    @select="go(item.href)"
                >
                    <component :is="item.icon" aria-hidden="true" />
                    {{ item.title }}
                </CommandItem>
            </CommandGroup>
            <CommandGroup heading="Preferencias">
                <CommandItem
                    v-for="item in themeItems"
                    :key="item.value"
                    :value="`theme-${item.value}`"
                    class="gap-3"
                    @select="setTheme(item.value)"
                >
                    <component :is="item.icon" aria-hidden="true" />
                    {{ item.title }}
                </CommandItem>
            </CommandGroup>
        </CommandList>
        <div
            class="flex items-center justify-between gap-4 border-t bg-surface-2/60 px-3 py-2 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-1.5">
                <Kbd><CornerDownLeft /></Kbd> para abrir
            </span>
            <span class="flex items-center gap-1.5">
                <Kbd>Esc</Kbd> para cerrar
            </span>
        </div>
    </CommandDialog>
</template>
