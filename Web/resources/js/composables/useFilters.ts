import type { VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { cleanQuery, currentPath, currentQuery } from '@/lib/pagination';

export type FilterPrimitive = string | number | boolean | null | undefined;
export type FilterValues = Record<string, FilterPrimitive | string[]>;

export type UseFiltersOptions<T extends FilterValues> = {
    /** URL destino. Por defecto la ruta actual. */
    url?: string;
    /** Recarga parcial: sólo estas props (p. ej. ['punches', 'filters']). */
    only?: string[];
    /** Milisegundos de espera para campos de texto. Default 350. */
    debounce?: number;
    /** Campos a los que se aplica el debounce. Default ['search', 'q']. */
    debounceKeys?: string[];
    /** Valores "vacíos" para reset() y activeCount. Default: null / []. */
    defaults?: Partial<T>;
    /** Parámetros de la URL actual que se conservan. Default sort, direction, per_page. */
    keepQuery?: string[];
    /** Elimina `page` al cambiar un filtro. Default true. */
    resetPage?: boolean;
    /** Opciones extra para router.get. */
    visit?: Omit<VisitOptions, 'only' | 'data' | 'method'>;
};

export type UseFiltersReturn<T extends FilterValues> = {
    filters: T;
    /** Aplica los filtros inmediatamente. */
    apply: () => void;
    /** Restablece todos los filtros a sus valores por defecto. */
    reset: () => void;
    /** Restablece un filtro concreto. */
    clear: (key: keyof T) => void;
    /** Número de filtros con valor distinto al por defecto. */
    activeCount: ComputedRef<number>;
    isDirty: ComputedRef<boolean>;
    processing: Ref<boolean>;
};

function isEmpty(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

function sameValue(a: unknown, b: unknown): boolean {
    if (isEmpty(a) && isEmpty(b)) {
        return true;
    }

    if (Array.isArray(a) && Array.isArray(b)) {
        return a.length === b.length && a.every((v, i) => v === b[i]);
    }

    return a === b;
}

/**
 * Filtros sincronizados con la query string mediante `router.get` de Inertia
 * (preserveState + preserveScroll + replace). Para listados con paginación
 * de servidor. Los campos de texto (`search`, `q`) se aplican con debounce;
 * el resto, inmediatamente. Al filtrar se vuelve a la página 1.
 *
 * ```ts
 * const props = defineProps<{ filters: { search?: string; status?: string } }>();
 * const { filters, activeCount, reset } = useFilters(
 *     { search: props.filters.search ?? '', status: props.filters.status ?? null },
 *     { only: ['punches', 'filters'] },
 * );
 * ```
 */
export function useFilters<T extends FilterValues>(
    initial: T,
    options: UseFiltersOptions<T> = {},
): UseFiltersReturn<T> {
    const filters = reactive({ ...initial }) as T;
    const processing = ref(false);
    const debounceMs = options.debounce ?? 350;
    const debounceKeys = options.debounceKeys ?? ['search', 'q'];
    const keepQuery = options.keepQuery ?? ['sort', 'direction', 'per_page'];
    const values = filters as Record<string, unknown>;

    const defaultFor = (key: string): unknown => {
        if (options.defaults && key in options.defaults) {
            return options.defaults[key as keyof T];
        }

        return Array.isArray(initial[key]) ? [] : null;
    };

    let timer: ReturnType<typeof setTimeout> | undefined;
    let snapshot: Record<string, unknown> = { ...initial };

    function visit(): void {
        clearTimeout(timer);

        const current = currentQuery();
        const kept: Record<string, unknown> = {};

        for (const key of keepQuery) {
            if (key in current) {
                kept[key] = current[key];
            }
        }

        if (options.resetPage === false && 'page' in current) {
            kept.page = current.page;
        }

        router.get(
            options.url ?? currentPath(),
            cleanQuery({ ...kept, ...values }),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...options.visit,
                only: options.only,
                onStart: () => {
                    processing.value = true;
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    }

    watch(
        () => ({ ...values }),
        (next) => {
            const changed = Object.keys(next).filter(
                (key) => !sameValue(next[key], snapshot[key]),
            );

            snapshot = { ...next };

            if (changed.length === 0) {
                return;
            }

            clearTimeout(timer);

            if (changed.every((key) => debounceKeys.includes(key))) {
                timer = setTimeout(visit, debounceMs);
            } else {
                visit();
            }
        },
        { deep: true },
    );

    const activeCount = computed(
        () =>
            Object.keys(initial).filter(
                (key) => !sameValue(values[key], defaultFor(key)),
            ).length,
    );

    function reset(): void {
        for (const key of Object.keys(initial)) {
            values[key] = defaultFor(key);
        }
    }

    function clear(key: keyof T): void {
        values[key as string] = defaultFor(key as string);
    }

    return {
        filters,
        apply: visit,
        reset,
        clear,
        activeCount,
        isDirty: computed(() => activeCount.value > 0),
        processing,
    };
}
