import type {
    LaravelPaginator,
    PaginationMeta,
    ResourcePaginator,
} from '@/types';

function isResourcePaginator<T>(
    value: LaravelPaginator<T> | ResourcePaginator<T>,
): value is ResourcePaginator<T> {
    return (
        typeof (value as ResourcePaginator<T>).meta === 'object' &&
        (value as ResourcePaginator<T>).meta !== null &&
        !('current_page' in value)
    );
}

/**
 * Normaliza un paginador de Laravel (`->paginate()`) o una colección de
 * API Resource paginada a `PaginationMeta` para <DataTable>.
 */
export function toPaginationMeta<T>(
    paginator: LaravelPaginator<T> | ResourcePaginator<T> | null | undefined,
): PaginationMeta | undefined {
    if (!paginator) {
        return undefined;
    }

    const source = isResourcePaginator(paginator) ? paginator.meta : paginator;

    return {
        currentPage: Number(source.current_page) || 1,
        lastPage: Number(source.last_page) || 1,
        perPage: Number(source.per_page) || 15,
        total: Number(source.total) || 0,
        from: source.from ?? null,
        to: source.to ?? null,
    };
}

/** Lee la query string actual como objeto plano. */
export function currentQuery(): Record<string, string | string[]> {
    if (typeof window === 'undefined') {
        return {};
    }

    const params = new URLSearchParams(window.location.search);
    const result: Record<string, string | string[]> = {};

    for (const key of new Set(params.keys())) {
        const values = params.getAll(key);
        const cleanKey = key.endsWith('[]') ? key.slice(0, -2) : key;

        result[cleanKey] =
            key.endsWith('[]') || values.length > 1 ? values : values[0];
    }

    return result;
}

/** Ruta actual sin query string. */
export function currentPath(): string {
    return typeof window === 'undefined' ? '/' : window.location.pathname;
}

/** Elimina valores vacíos para no ensuciar la URL. */
export function cleanQuery(
    query: Record<string, unknown>,
): Record<string, string | number | boolean | string[]> {
    const result: Record<string, string | number | boolean | string[]> = {};

    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (Array.isArray(value)) {
            if (value.length > 0) {
                result[key] = value.map(String);
            }

            continue;
        }

        if (
            typeof value === 'string' ||
            typeof value === 'number' ||
            typeof value === 'boolean'
        ) {
            result[key] = value;
        }
    }

    return result;
}
