<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bell, CheckCheck, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import DateTimeDisplay from '@/components/app/DateTimeDisplay.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { toPaginationMeta } from '@/lib/pagination';
import { TONE_DOT_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { LaravelPaginator, Tone } from '@/types';

type NotificationItem = {
    id: string;
    title: string;
    body: string | null;
    kind: string | null;
    tone: Tone;
    url: string | null;
    created_at: string | null;
    read_at: string | null;
};

const props = defineProps<{
    notifications: LaravelPaginator<NotificationItem>;
    filters: { filtro: 'todas' | 'no-leidas' };
    unread: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notificaciones', href: '/notificaciones' }],
    },
});

const meta = computed(() => toPaginationMeta(props.notifications));
const markingAll = ref(false);
const busyId = ref<string | null>(null);

function setFilter(value: unknown): void {
    if (value !== 'todas' && value !== 'no-leidas') {
        return;
    }

    router.get(
        '/notificaciones',
        value === 'no-leidas' ? { filtro: value } : {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function goToPage(page: number): void {
    router.get(
        '/notificaciones',
        {
            ...(props.filters.filtro === 'no-leidas'
                ? { filtro: 'no-leidas' }
                : {}),
            ...(page > 1 ? { page } : {}),
        },
        { preserveScroll: false, preserveState: true },
    );
}

function markAsRead(item: NotificationItem, then?: () => void): void {
    if (item.read_at) {
        then?.();

        return;
    }

    router.patch(
        `/notificaciones/${item.id}/leida`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busyId.value = item.id;
            },
            onFinish: () => {
                busyId.value = null;
            },
            onSuccess: () => then?.(),
        },
    );
}

function open(item: NotificationItem): void {
    if (!item.url) {
        markAsRead(item);

        return;
    }

    const url = item.url;

    markAsRead(item, () => router.visit(url));
}

function markAll(): void {
    router.post(
        '/notificaciones/leer-todas',
        {},
        {
            preserveScroll: true,
            onStart: () => {
                markingAll.value = true;
            },
            onFinish: () => {
                markingAll.value = false;
            },
        },
    );
}
</script>

<template>
    <PageHeader title="Notificaciones" description="Avisos de tus solicitudes.">
        <template #meta>
            <span
                class="inline-flex items-center rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-semibold text-brand-soft-foreground"
            >
                <span class="numeric">{{ unread }}</span
                >&nbsp;sin leer
            </span>
        </template>
        <template #actions>
            <Button
                variant="outline"
                :disabled="unread === 0 || markingAll"
                @click="markAll"
            >
                <Spinner v-if="markingAll" />
                <CheckCheck v-else />
                Marcar todas como leídas
            </Button>
        </template>
    </PageHeader>

    <section
        class="overflow-hidden rounded-2xl border bg-card shadow-elev-1"
        aria-label="Bandeja de notificaciones"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3"
        >
            <ToggleGroup
                type="single"
                variant="outline"
                :model-value="filters.filtro"
                aria-label="Filtrar notificaciones"
                @update:model-value="setFilter"
            >
                <ToggleGroupItem value="todas" class="h-10 px-4 md:h-9">
                    Todas
                </ToggleGroupItem>
                <ToggleGroupItem value="no-leidas" class="h-10 px-4 md:h-9">
                    No leídas
                </ToggleGroupItem>
            </ToggleGroup>
            <p v-if="meta" class="text-sm text-muted-foreground">
                <span class="numeric">{{ meta.total }}</span>
                {{ meta.total === 1 ? 'notificación' : 'notificaciones' }}
            </p>
        </div>

        <EmptyState
            v-if="notifications.data.length === 0"
            :mascot="filters.filtro === 'no-leidas' ? 'saludo' : false"
            :icon="Bell"
            :title="
                filters.filtro === 'no-leidas'
                    ? '¡Estás al día!'
                    : 'Aún no tienes notificaciones'
            "
            :description="
                filters.filtro === 'no-leidas'
                    ? 'Nada pendiente por leer.'
                    : 'Aquí aparecerán tus avisos.'
            "
        />

        <ul v-else class="divide-y">
            <li
                v-for="item in notifications.data"
                :key="item.id"
                :class="
                    cn(
                        'group relative flex gap-3 px-4 py-4 transition-standard hover:bg-surface-2/70 sm:gap-4 sm:px-5',
                        !item.read_at && 'bg-brand-soft/35',
                    )
                "
            >
                <span
                    :class="
                        cn(
                            'mt-2 size-2.5 shrink-0 rounded-full',
                            item.read_at
                                ? 'bg-border-strong'
                                : TONE_DOT_CLASSES[item.tone],
                        )
                    "
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <div
                        class="flex flex-col gap-0.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4"
                    >
                        <p
                            :class="
                                cn(
                                    'text-sm text-foreground',
                                    item.read_at
                                        ? 'font-medium'
                                        : 'font-semibold',
                                )
                            "
                        >
                            {{ item.title }}
                            <span v-if="!item.read_at" class="sr-only">
                                (sin leer)</span
                            >
                        </p>
                        <DateTimeDisplay
                            :value="item.created_at"
                            format="relative"
                            class="shrink-0 text-xs text-muted-foreground"
                        />
                    </div>
                    <p
                        v-if="item.body"
                        class="mt-1 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ item.body }}
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <Button
                            v-if="item.url"
                            size="sm"
                            variant="outline"
                            class="h-9"
                            @click="open(item)"
                        >
                            Ver detalle
                            <ChevronRight />
                        </Button>
                        <Button
                            v-if="!item.read_at"
                            size="sm"
                            variant="ghost"
                            class="h-9 text-muted-foreground"
                            :disabled="busyId === item.id"
                            @click="markAsRead(item)"
                        >
                            <Spinner v-if="busyId === item.id" />
                            Marcar como leída
                        </Button>
                    </div>
                </div>
            </li>
        </ul>

        <div
            v-if="meta && meta.lastPage > 1"
            class="flex justify-center border-t px-4 py-3 sm:justify-end"
        >
            <Pagination
                v-slot="{ page }"
                :page="meta.currentPage"
                :total="meta.total"
                :items-per-page="meta.perPage"
                :sibling-count="1"
                show-edges
                class="mx-0 w-auto"
                @update:page="goToPage"
            >
                <PaginationContent v-slot="{ items }" class="gap-1">
                    <PaginationPrevious size="sm" class="h-9" />
                    <template v-for="(entry, index) in items" :key="index">
                        <PaginationItem
                            v-if="entry.type === 'page'"
                            :value="entry.value"
                            :is-active="entry.value === page"
                            size="icon-sm"
                            class="hidden size-9 numeric sm:inline-flex"
                        >
                            {{ entry.value }}
                        </PaginationItem>
                        <PaginationEllipsis
                            v-else
                            :index="index"
                            class="hidden sm:flex"
                        />
                    </template>
                    <span
                        class="px-2 numeric text-sm text-muted-foreground sm:hidden"
                        >{{ page }} / {{ meta.lastPage }}</span
                    >
                    <PaginationNext size="sm" class="h-9" />
                </PaginationContent>
            </Pagination>
        </div>
    </section>
</template>
