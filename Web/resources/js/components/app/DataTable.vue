<script setup lang="ts" generic="T">
import { router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    SearchX,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { HTMLAttributes } from 'vue';
import EmptyState from '@/components/app/EmptyState.vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { formatNumber } from '@/lib/format';
import { cleanQuery, currentPath, currentQuery } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import type { DataTableColumn, PaginationMeta, SortState } from '@/types';

type RowKey = string | number;

const props = withDefaults(
    defineProps<{
        rows: T[];
        columns: DataTableColumn<T>[];
        /** Propiedad o función que identifica cada fila. Default 'id'. */
        rowKey?: keyof T | ((row: T) => RowKey);
        /** Paginación de servidor (usa `toPaginationMeta(paginator)`). */
        pagination?: PaginationMeta;
        /** Orden actual (normalmente de `props.filters`). */
        sort?: SortState | null;
        /** Casillas de selección por fila (v-model:selected). */
        selectable?: boolean;
        /** Estado de carga externo. */
        loading?: boolean;
        /**
         * true (default): página/orden/tamaño se aplican con `router.get`
         * conservando la query string. false: sólo emite eventos.
         */
        server?: boolean;
        /** Recarga parcial de Inertia (p. ej. ['punches']). */
        only?: string[];
        pageParam?: string;
        perPageParam?: string;
        sortParam?: string;
        directionParam?: string;
        perPageOptions?: number[];
        /** Fila clicable: URL a la que navegar. */
        rowHref?: (row: T) => string | null | undefined;
        emptyTitle?: string;
        emptyDescription?: string;
        emptyIcon?: LucideIcon;
        /** Descripción accesible de la tabla. */
        caption?: string;
        /** Envuelve la tabla en una tarjeta. Default true. */
        card?: boolean;
        /** En pantallas < md muestra cada fila como tarjeta. Default true. */
        mobileCards?: boolean;
        skeletonRows?: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        rowKey: 'id' as never,
        pagination: undefined,
        sort: null,
        selectable: false,
        loading: false,
        server: true,
        only: undefined,
        pageParam: 'page',
        perPageParam: 'per_page',
        sortParam: 'sort',
        directionParam: 'direction',
        perPageOptions: () => [15, 25, 50, 100],
        rowHref: undefined,
        emptyTitle: 'Sin resultados',
        emptyDescription:
            'No hay registros que coincidan con los filtros aplicados.',
        emptyIcon: undefined,
        caption: undefined,
        card: true,
        mobileCards: true,
        skeletonRows: 6,
    },
);

const selected = defineModel<RowKey[]>('selected', { default: () => [] });

const emit = defineEmits<{
    sort: [sort: SortState | null];
    page: [page: number];
    perPage: [perPage: number];
    rowClick: [row: T];
}>();

const navigating = ref(false);
const isLoading = computed(() => props.loading || navigating.value);

const keyOf = (row: T): RowKey => {
    if (typeof props.rowKey === 'function') {
        return props.rowKey(row);
    }

    return (row as Record<PropertyKey, unknown>)[
        props.rowKey as PropertyKey
    ] as RowKey;
};

const valueOf = (row: T, column: DataTableColumn<T>): unknown =>
    column.accessor
        ? column.accessor(row)
        : (row as Record<string, unknown>)[column.key];

const HIDE_BELOW = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
    xl: 'hidden xl:table-cell',
} as const;

function cellClass(column: DataTableColumn<T>): string {
    return cn(
        column.hideBelow && HIDE_BELOW[column.hideBelow],
        (column.align === 'right' || column.numeric) && 'text-right',
        column.align === 'center' && 'text-center',
        column.numeric && 'numeric',
        column.class,
    );
}

/* ---------------------------------------------------------- Navegación -- */
function visit(params: Record<string, unknown>): void {
    const query = cleanQuery({ ...currentQuery(), ...params });

    router.get(currentPath(), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: props.only,
        onStart: () => {
            navigating.value = true;
        },
        onFinish: () => {
            navigating.value = false;
        },
    });
}

function sortKeyOf(column: DataTableColumn<T>): string {
    return column.sortKey ?? column.key;
}

function sortStateFor(column: DataTableColumn<T>): 'asc' | 'desc' | null {
    return props.sort && props.sort.key === sortKeyOf(column)
        ? props.sort.direction
        : null;
}

function toggleSort(column: DataTableColumn<T>): void {
    if (!column.sortable) {
        return;
    }

    const state = sortStateFor(column);
    const next: SortState | null =
        state === null
            ? { key: sortKeyOf(column), direction: 'asc' }
            : state === 'asc'
              ? { key: sortKeyOf(column), direction: 'desc' }
              : null;

    emit('sort', next);

    if (props.server) {
        visit({
            [props.sortParam]: next?.key ?? null,
            [props.directionParam]: next?.direction ?? null,
            [props.pageParam]: null,
        });
    }
}

function goToPage(page: number): void {
    if (!props.pagination || page === props.pagination.currentPage) {
        return;
    }

    emit('page', page);

    if (props.server) {
        visit({ [props.pageParam]: page > 1 ? page : null });
    }
}

function changePerPage(value: unknown): void {
    const perPage = Number(value);

    emit('perPage', perPage);

    if (props.server) {
        visit({ [props.perPageParam]: perPage, [props.pageParam]: null });
    }
}

/* ------------------------------------------------------------ Selección -- */
const pageKeys = computed(() => props.rows.map(keyOf));

const allSelected = computed<boolean | 'indeterminate'>(() => {
    const count = pageKeys.value.filter((key) =>
        selected.value.includes(key),
    ).length;

    if (count === 0) {
        return false;
    }

    return count === pageKeys.value.length ? true : 'indeterminate';
});

function toggleAll(value: boolean | 'indeterminate'): void {
    if (value === true) {
        selected.value = Array.from(
            new Set([...selected.value, ...pageKeys.value]),
        );
    } else {
        selected.value = selected.value.filter(
            (key) => !pageKeys.value.includes(key),
        );
    }
}

function toggleRow(row: T, value: boolean | 'indeterminate'): void {
    const key = keyOf(row);

    selected.value =
        value === true
            ? [...selected.value.filter((k) => k !== key), key]
            : selected.value.filter((k) => k !== key);
}

function clearSelection(): void {
    selected.value = [];
}

/* --------------------------------------------------------------- Filas -- */
function onRowClick(row: T, event: MouseEvent | KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    if (
        target?.closest(
            'a, button, input, [role="checkbox"], [data-no-row-click]',
        )
    ) {
        return;
    }

    emit('rowClick', row);

    const href = props.rowHref?.(row);

    if (href) {
        router.visit(href);
    }
}

/* ------------------------------------------------------ Vista móvil -- */
const titleColumn = computed(
    () =>
        props.columns.find((column) => column.mobile === 'title') ??
        props.columns.find((column) => column.mobile !== 'hidden'),
);

const detailColumns = computed(() =>
    props.columns.filter(
        (column) => column !== titleColumn.value && column.mobile !== 'hidden',
    ),
);

const sortableColumns = computed(() =>
    props.columns.filter((column) => column.sortable),
);

const mobileSortValue = computed(() =>
    props.sort ? `${props.sort.key}:${props.sort.direction}` : '',
);

function onMobileSort(value: unknown): void {
    const [key, direction] = String(value ?? '').split(':');
    const next: SortState | null =
        key && (direction === 'asc' || direction === 'desc')
            ? { key, direction }
            : null;

    emit('sort', next);

    if (props.server) {
        visit({
            [props.sortParam]: next?.key ?? null,
            [props.directionParam]: next?.direction ?? null,
            [props.pageParam]: null,
        });
    }
}

const columnCount = computed(
    () => props.columns.length + (props.selectable ? 1 : 0),
);

const rangeText = computed(() => {
    const meta = props.pagination;

    if (!meta || meta.total === 0) {
        return '0 registros';
    }

    const from = meta.from ?? (meta.currentPage - 1) * meta.perPage + 1;
    const to = meta.to ?? Math.min(meta.total, meta.currentPage * meta.perPage);

    return `${formatNumber(from)}–${formatNumber(to)} de ${formatNumber(meta.total)}`;
});

defineExpose({ clearSelection });
</script>

<template>
    <div
        data-slot="data-table"
        :class="
            cn(
                'relative flex min-w-0 flex-col',
                card &&
                    (mobileCards
                        ? 'md:overflow-hidden md:rounded-2xl md:border md:bg-card md:shadow-elev-1'
                        : 'overflow-hidden rounded-2xl border bg-card shadow-elev-1'),
                $props.class,
            )
        "
    >
        <div
            v-if="$slots.toolbar"
            :class="
                cn(
                    'border-b px-4 py-3',
                    !card && 'px-0 pt-0',
                    mobileCards && 'max-md:border-b-0 max-md:px-0 max-md:pt-0',
                )
            "
        >
            <slot name="toolbar" />
        </div>

        <!-- Barra de acciones en lote -->
        <div
            v-if="selectable && selected.length > 0"
            class="flex flex-wrap items-center gap-3 border-b bg-primary-soft/60 px-4 py-2.5 text-sm max-md:mb-3 max-md:rounded-xl max-md:border"
        >
            <span class="font-medium text-primary-soft-foreground">
                <span class="numeric">{{ selected.length }}</span>
                {{ selected.length === 1 ? 'seleccionado' : 'seleccionados' }}
            </span>
            <button
                type="button"
                class="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                @click="clearSelection"
            >
                Quitar selección
            </button>
            <div class="ml-auto flex items-center gap-2">
                <slot
                    name="bulk-actions"
                    :selected="selected"
                    :clear="clearSelection"
                />
            </div>
        </div>

        <!-- Indicador de carga -->
        <div
            v-if="isLoading"
            class="pointer-events-none absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden"
            aria-hidden="true"
        >
            <div
                class="h-full w-1/3 animate-[datatable-progress_1.1s_ease-in-out_infinite] bg-primary"
            />
        </div>

        <!-- Vista de tarjetas (móvil) -->
        <div v-if="mobileCards" class="md:hidden">
            <div
                v-if="
                    (sortableColumns.length > 0 || selectable) &&
                    rows.length > 0
                "
                class="mb-3 flex items-center justify-between gap-3"
            >
                <label
                    v-if="selectable"
                    class="flex min-h-10 items-center gap-2.5 text-sm text-muted-foreground"
                >
                    <Checkbox
                        class="size-5"
                        :model-value="allSelected"
                        aria-label="Seleccionar página"
                        @update:model-value="toggleAll"
                    />
                    Todos
                </label>
                <Select
                    v-if="sortableColumns.length > 0"
                    :model-value="mobileSortValue"
                    @update:model-value="onMobileSort"
                >
                    <SelectTrigger
                        class="ml-auto h-10 w-auto min-w-44 bg-card"
                        aria-label="Ordenar"
                    >
                        <span class="text-muted-foreground">Orden:</span>
                        <SelectValue placeholder="Predeterminado" />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <template
                            v-for="column in sortableColumns"
                            :key="column.key"
                        >
                            <SelectItem
                                :value="`${column.sortKey ?? column.key}:asc`"
                            >
                                {{ column.label }} ↑
                            </SelectItem>
                            <SelectItem
                                :value="`${column.sortKey ?? column.key}:desc`"
                            >
                                {{ column.label }} ↓
                            </SelectItem>
                        </template>
                    </SelectContent>
                </Select>
            </div>

            <ul
                v-if="isLoading && rows.length === 0"
                class="grid gap-3"
                aria-hidden="true"
            >
                <li
                    v-for="n in 3"
                    :key="`m-skeleton-${n}`"
                    class="space-y-3 rounded-2xl border bg-card p-4"
                >
                    <Skeleton class="h-5 w-2/3" />
                    <Skeleton class="h-4 w-1/2" />
                    <Skeleton class="h-4 w-1/3" />
                </li>
            </ul>

            <div
                v-else-if="rows.length === 0"
                class="rounded-2xl border bg-card"
            >
                <slot name="empty">
                    <EmptyState
                        :title="emptyTitle"
                        :description="emptyDescription"
                        :icon="emptyIcon ?? SearchX"
                        size="sm"
                    >
                        <template v-if="$slots['empty-actions']" #actions>
                            <slot name="empty-actions" />
                        </template>
                    </EmptyState>
                </slot>
            </div>

            <ul
                v-else
                :class="
                    cn(
                        'grid gap-3 transition-opacity',
                        isLoading && 'opacity-55',
                    )
                "
                :aria-busy="isLoading || undefined"
            >
                <li
                    v-for="(row, rowIndex) in rows"
                    :key="keyOf(row)"
                    :data-state="
                        selected.includes(keyOf(row)) ? 'selected' : undefined
                    "
                    :class="
                        cn(
                            'rounded-2xl border bg-card p-4 shadow-elev-1 transition-standard data-[state=selected]:border-brand-strong/50 data-[state=selected]:bg-primary-soft/40',
                            rowHref &&
                                'cursor-pointer focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none active:scale-[0.99]',
                        )
                    "
                    :tabindex="rowHref ? 0 : undefined"
                    :data-row-link="rowHref ? '' : undefined"
                    @click="onRowClick(row, $event)"
                    @keydown.enter.self="onRowClick(row, $event)"
                >
                    <div class="flex items-start gap-3">
                        <Checkbox
                            v-if="selectable"
                            class="mt-0.5 size-5"
                            :model-value="selected.includes(keyOf(row))"
                            aria-label="Seleccionar"
                            @update:model-value="
                                (value) => toggleRow(row, value)
                            "
                        />
                        <div
                            v-if="titleColumn"
                            class="min-w-0 flex-1 text-[0.9375rem] font-semibold"
                        >
                            <slot
                                :name="`cell-${titleColumn.key}`"
                                :row="row"
                                :value="valueOf(row, titleColumn)"
                                :index="rowIndex"
                            >
                                {{ valueOf(row, titleColumn) ?? '—' }}
                            </slot>
                        </div>
                    </div>
                    <dl
                        v-if="detailColumns.length > 0"
                        class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 border-t pt-3"
                    >
                        <div
                            v-for="column in detailColumns"
                            :key="column.key"
                            :class="
                                cn(
                                    'min-w-0 space-y-1',
                                    column.mobile === 'full' && 'col-span-2',
                                )
                            "
                        >
                            <dt
                                class="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ column.label }}
                            </dt>
                            <dd
                                :class="
                                    cn(
                                        'text-sm break-words text-foreground',
                                        column.numeric && 'numeric',
                                    )
                                "
                            >
                                <slot
                                    :name="`cell-${column.key}`"
                                    :row="row"
                                    :value="valueOf(row, column)"
                                    :index="rowIndex"
                                >
                                    <span
                                        v-if="
                                            valueOf(row, column) === null ||
                                            valueOf(row, column) ===
                                                undefined ||
                                            valueOf(row, column) === ''
                                        "
                                        class="text-subtle-foreground"
                                        >—</span
                                    >
                                    <template v-else>{{
                                        valueOf(row, column)
                                    }}</template>
                                </slot>
                            </dd>
                        </div>
                    </dl>
                </li>
            </ul>
        </div>

        <div
            :class="
                cn(
                    'relative w-full overflow-x-auto',
                    mobileCards && 'hidden md:block',
                )
            "
        >
            <table
                class="w-full caption-bottom border-separate border-spacing-0 text-sm"
                :aria-busy="isLoading || undefined"
            >
                <caption v-if="caption" class="sr-only">
                    {{
                        caption
                    }}
                </caption>
                <thead class="bg-surface-2/70">
                    <tr>
                        <th
                            v-if="selectable"
                            scope="col"
                            class="w-12 border-b py-3 pr-0 pl-4 text-left"
                        >
                            <Checkbox
                                :model-value="allSelected"
                                aria-label="Seleccionar página"
                                :disabled="rows.length === 0"
                                @update:model-value="toggleAll"
                            />
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            :aria-sort="
                                sortStateFor(column) === 'asc'
                                    ? 'ascending'
                                    : sortStateFor(column) === 'desc'
                                      ? 'descending'
                                      : undefined
                            "
                            :class="
                                cn(
                                    'h-11 border-b px-4 text-left align-middle text-[0.6875rem] font-bold tracking-[0.06em] whitespace-nowrap text-muted-foreground uppercase first:pl-5 last:pr-5',
                                    cellClass(column),
                                )
                            "
                        >
                            <slot
                                :name="`header-${column.key}`"
                                :column="column"
                            >
                                <button
                                    v-if="column.sortable"
                                    type="button"
                                    :class="
                                        cn(
                                            '-mx-1.5 inline-flex items-center gap-1 rounded-md px-1.5 py-1 uppercase transition-standard hover:bg-accent hover:text-foreground',
                                            sortStateFor(column) &&
                                                'text-foreground',
                                        )
                                    "
                                    @click="toggleSort(column)"
                                >
                                    {{ column.label }}
                                    <ArrowUp
                                        v-if="sortStateFor(column) === 'asc'"
                                        class="icon-xs"
                                        aria-hidden="true"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortStateFor(column) === 'desc'
                                        "
                                        class="icon-xs"
                                        aria-hidden="true"
                                    />
                                    <ChevronsUpDown
                                        v-else
                                        class="icon-xs opacity-40"
                                        aria-hidden="true"
                                    />
                                </button>
                                <template v-else>{{ column.label }}</template>
                            </slot>
                        </th>
                    </tr>
                </thead>

                <tbody
                    :class="
                        cn(
                            'transition-opacity duration-200',
                            isLoading && rows.length > 0 && 'opacity-55',
                        )
                    "
                >
                    <!-- Skeleton -->
                    <template v-if="isLoading && rows.length === 0">
                        <tr v-for="n in skeletonRows" :key="`skeleton-${n}`">
                            <td
                                v-if="selectable"
                                class="border-b py-4 pr-0 pl-4"
                            >
                                <Skeleton class="size-4 rounded" />
                            </td>
                            <td
                                v-for="(column, index) in columns"
                                :key="column.key"
                                :class="
                                    cn(
                                        'border-b px-4 py-4 first:pl-5 last:pr-5',
                                        cellClass(column),
                                    )
                                "
                            >
                                <Skeleton
                                    :class="
                                        cn(
                                            'h-4 rounded',
                                            index === 0 ? 'w-40' : 'w-24',
                                            (column.align === 'right' ||
                                                column.numeric) &&
                                                'ml-auto w-16',
                                        )
                                    "
                                />
                            </td>
                        </tr>
                    </template>

                    <!-- Vacío -->
                    <tr v-else-if="rows.length === 0">
                        <td :colspan="columnCount" class="border-b">
                            <slot name="empty">
                                <EmptyState
                                    :title="emptyTitle"
                                    :description="emptyDescription"
                                    :icon="emptyIcon ?? SearchX"
                                    size="sm"
                                >
                                    <template
                                        v-if="$slots['empty-actions']"
                                        #actions
                                    >
                                        <slot name="empty-actions" />
                                    </template>
                                </EmptyState>
                            </slot>
                        </td>
                    </tr>

                    <!-- Filas -->
                    <tr
                        v-for="(row, rowIndex) in rows"
                        v-else
                        :key="keyOf(row)"
                        :data-state="
                            selected.includes(keyOf(row))
                                ? 'selected'
                                : undefined
                        "
                        :class="
                            cn(
                                'group/row transition-colors duration-150 hover:bg-surface-2/80 data-[state=selected]:bg-primary-soft/50',
                                rowHref &&
                                    'cursor-pointer focus-visible:bg-surface-2/80 focus-visible:outline-none',
                            )
                        "
                        :tabindex="rowHref ? 0 : undefined"
                        :data-row-link="rowHref ? '' : undefined"
                        @click="onRowClick(row, $event)"
                        @keydown.enter.self="onRowClick(row, $event)"
                    >
                        <td
                            v-if="selectable"
                            class="border-b py-3.5 pr-0 pl-4 align-middle group-last/row:border-b-0"
                        >
                            <Checkbox
                                :model-value="selected.includes(keyOf(row))"
                                aria-label="Seleccionar fila"
                                @update:model-value="
                                    (value) => toggleRow(row, value)
                                "
                            />
                        </td>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="
                                cn(
                                    'border-b px-4 py-3.5 align-middle text-foreground group-last/row:border-b-0 first:pl-5 last:pr-5',
                                    cellClass(column),
                                )
                            "
                        >
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :value="valueOf(row, column)"
                                :index="rowIndex"
                            >
                                <span
                                    v-if="
                                        valueOf(row, column) === null ||
                                        valueOf(row, column) === undefined ||
                                        valueOf(row, column) === ''
                                    "
                                    class="text-subtle-foreground"
                                    >—</span
                                >
                                <template v-else>{{
                                    valueOf(row, column)
                                }}</template>
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pie con paginación -->
        <div
            v-if="
                pagination &&
                (pagination.total > 0 || pagination.currentPage > 1)
            "
            :class="
                cn(
                    'flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
                    !card && 'px-0',
                    mobileCards && 'max-md:mt-3 max-md:border-t-0 max-md:px-0',
                )
            "
        >
            <div class="flex items-center gap-3 text-sm text-muted-foreground">
                <span class="numeric whitespace-nowrap">{{ rangeText }}</span>
                <span
                    class="hidden h-4 w-px bg-border sm:block"
                    aria-hidden="true"
                />
                <div class="hidden items-center gap-2 sm:flex">
                    <span>Filas</span>
                    <Select
                        :model-value="pagination.perPage"
                        @update:model-value="changePerPage"
                    >
                        <SelectTrigger
                            size="sm"
                            class="h-8 w-[4.5rem]"
                            aria-label="Filas por página"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in perPageOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ option }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <Pagination
                v-if="pagination.lastPage > 1"
                v-slot="{ page }"
                :page="pagination.currentPage"
                :total="pagination.total"
                :items-per-page="pagination.perPage"
                :sibling-count="1"
                show-edges
                class="mx-0 w-auto justify-end"
                @update:page="goToPage"
            >
                <PaginationContent v-slot="{ items }" class="gap-1">
                    <PaginationPrevious size="sm" class="h-8">
                        <ChevronLeft aria-hidden="true" />
                        <span class="hidden sm:block">Anterior</span>
                    </PaginationPrevious>
                    <template v-for="(item, index) in items" :key="index">
                        <PaginationItem
                            v-if="item.type === 'page'"
                            :value="item.value"
                            :is-active="item.value === page"
                            size="icon-sm"
                            class="hidden numeric sm:inline-flex"
                        >
                            {{ item.value }}
                        </PaginationItem>
                        <PaginationEllipsis
                            v-else
                            :index="index"
                            class="hidden sm:flex"
                        />
                    </template>
                    <span
                        class="px-2 numeric text-sm text-muted-foreground sm:hidden"
                    >
                        {{ page }} / {{ pagination.lastPage }}
                    </span>
                    <PaginationNext size="sm" class="h-8">
                        <span class="hidden sm:block">Siguiente</span>
                        <ChevronRight aria-hidden="true" />
                    </PaginationNext>
                </PaginationContent>
            </Pagination>
        </div>
    </div>
</template>

<style>
@keyframes datatable-progress {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(300%);
    }
}
</style>
