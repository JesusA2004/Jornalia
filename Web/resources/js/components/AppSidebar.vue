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
        <SidebarHeader
            class="border-b border-sidebar-border px-3 pt-4 pb-3 group-data-[collapsible=icon]:px-2"
        >
            <Link
                href="/dashboard"
                aria-label="Ir al panel"
                class="flex h-11 items-center rounded-lg px-1.5 outline-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 focus-visible:ring-2 focus-visible:ring-sidebar-ring"
            >
                <AppLogo
                    size="sm"
                    inverted
                    class="group-data-[collapsible=icon]:hidden"
                />
                <img
                    :src="BRAND.logoUrl"
                    :alt="BRAND.name"
                    class="hidden h-auto w-9 group-data-[collapsible=icon]:block"
                    draggable="false"
                />
            </Link>
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
