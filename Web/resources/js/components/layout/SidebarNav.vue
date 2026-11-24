<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavGroup } from '@/types';

defineProps<{
    groups: NavGroup[];
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.title" class="py-1.5">
        <SidebarGroupLabel
            class="h-7 px-3 text-[0.625rem] font-semibold tracking-[0.18em] text-sidebar-foreground/45 uppercase"
        >
            {{ group.title }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem
                v-for="item in group.items"
                :key="toUrl(item.href)"
            >
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                    class="relative h-10 gap-3 rounded-md px-3 text-[0.8125rem] font-medium text-sidebar-foreground/75 transition-standard before:absolute before:inset-y-1.5 before:left-0 before:w-[3px] before:rounded-full before:bg-[var(--sidebar-gold)] before:opacity-0 group-data-[collapsible=icon]:before:hidden hover:bg-sidebar-accent hover:text-white data-[active=true]:bg-sidebar-accent data-[active=true]:font-semibold data-[active=true]:text-white data-[active=true]:before:opacity-100 md:h-9 [&>svg]:size-4 [&>svg]:text-sidebar-foreground/50 data-[active=true]:[&>svg]:text-[var(--sidebar-gold)]"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" aria-hidden="true" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
