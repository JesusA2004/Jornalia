<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/layout/NotificationBell.vue';
import OrganizationSwitcher from '@/components/layout/OrganizationSwitcher.vue';
import ThemeToggle from '@/components/layout/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCommandMenu } from '@/composables/useCommandMenu';
import { useNavigation } from '@/composables/useNavigation';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { show } = useCommandMenu();
const { fallbackBreadcrumbs } = useNavigation();

const crumbs = computed(() =>
    props.breadcrumbs.length > 0
        ? props.breadcrumbs
        : fallbackBreadcrumbs.value,
);

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.userAgent);
</script>

<template>
    <header
        class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b bg-background/85 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-background/70 md:rounded-t-2xl md:px-5"
    >
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <SidebarTrigger
                class="-ml-1 size-8 text-muted-foreground hover:text-foreground"
            />
            <Separator
                orientation="vertical"
                class="mr-1 data-[orientation=vertical]:h-4"
            />
            <Breadcrumbs v-if="crumbs.length > 0" :breadcrumbs="crumbs" />
        </div>

        <div class="flex shrink-0 items-center gap-1.5">
            <button
                type="button"
                class="hidden h-9 w-60 items-center gap-2 rounded-lg border bg-card px-3 text-sm text-subtle-foreground shadow-elev-1 transition-standard hover:border-border-strong hover:text-muted-foreground md:flex dark:bg-input/30"
                aria-label="Abrir buscador"
                @click="show"
            >
                <Search class="icon-sm" aria-hidden="true" />
                <span class="flex-1 text-left">Buscar…</span>
                <KbdGroup>
                    <Kbd>{{ isMac ? '⌘' : 'Ctrl' }}</Kbd>
                    <Kbd>K</Kbd>
                </KbdGroup>
            </button>
            <Button
                variant="ghost"
                size="icon"
                class="size-9 rounded-lg text-muted-foreground md:hidden"
                aria-label="Abrir buscador"
                @click="show"
            >
                <Search class="icon-md" />
            </Button>
            <OrganizationSwitcher />
            <NotificationBell />
            <ThemeToggle />
        </div>
    </header>
</template>
