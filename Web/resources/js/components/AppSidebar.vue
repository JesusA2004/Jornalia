<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import SidebarNav from '@/components/layout/SidebarNav.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarRail,
} from '@/components/ui/sidebar';
import { useNavigation } from '@/composables/useNavigation';
import { BRAND } from '@/lib/brand';

const { groups } = useNavigation();
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="relative px-3 pt-4 pb-3">
            <Link
                href="/dashboard"
                aria-label="Ir al panel"
                class="flex h-12 items-center rounded-xl px-1.5 outline-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 focus-visible:ring-2 focus-visible:ring-sidebar-ring"
            >
                <AppLogo
                    size="md"
                    inverted
                    class="group-data-[collapsible=icon]:hidden"
                />
                <img
                    :src="BRAND.iconSmallUrl"
                    :alt="BRAND.name"
                    class="hidden size-8 rounded-lg group-data-[collapsible=icon]:block"
                />
            </Link>
            <span
                class="pointer-events-none absolute inset-x-4 bottom-0 h-px bg-brand-gradient opacity-60 group-data-[collapsible=icon]:inset-x-2"
                aria-hidden="true"
            />
        </SidebarHeader>

        <SidebarContent class="gap-1 pt-2 pb-4">
            <SidebarNav :groups="groups" />
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border pt-2">
            <NavUser />
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
    <slot />
</template>
