<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();
</script>

<template>
    <Breadcrumb aria-label="Ruta de navegación" class="min-w-0">
        <BreadcrumbList class="flex-nowrap gap-1.5 text-[0.8125rem] sm:gap-2">
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem
                    :class="
                        index < breadcrumbs.length - 1
                            ? 'hidden shrink-0 md:inline-flex'
                            : 'min-w-0'
                    "
                >
                    <BreadcrumbPage
                        v-if="index === breadcrumbs.length - 1"
                        class="truncate font-semibold"
                        >{{ item.title }}</BreadcrumbPage
                    >
                    <BreadcrumbLink v-else-if="item.href" as-child>
                        <Link :href="item.href">{{ item.title }}</Link>
                    </BreadcrumbLink>
                    <span v-else class="text-muted-foreground">{{
                        item.title
                    }}</span>
                </BreadcrumbItem>
                <BreadcrumbSeparator
                    v-if="index !== breadcrumbs.length - 1"
                    class="hidden md:block"
                />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
