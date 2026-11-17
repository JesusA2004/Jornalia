<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import CommandMenu from '@/components/layout/CommandMenu.vue';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <TooltipProvider :delay-duration="250">
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                class="min-w-0 overflow-x-clip md:rounded-2xl md:border md:border-sidebar-border md:shadow-elev-2"
            >
                <AppSidebarHeader :breadcrumbs="breadcrumbs" />
                <div
                    id="contenido"
                    class="mx-auto flex w-full max-w-[90rem] flex-1 flex-col gap-6 px-4 py-6 md:px-8 md:py-8"
                >
                    <slot />
                </div>
            </AppContent>
            <CommandMenu />
            <Toaster position="top-right" :close-button="true" />
        </AppShell>
    </TooltipProvider>
</template>
