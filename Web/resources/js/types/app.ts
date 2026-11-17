/**
 * Tipos compartidos de la capa de presentación (layout y componentes de
 * producto). Los tipos de dominio de cada módulo viven en `types/<modulo>.ts`.
 */

/** Tonos semánticos disponibles en el sistema de diseño. */
export type Tone =
    | 'neutral'
    | 'primary'
    | 'brand'
    | 'success'
    | 'warning'
    | 'danger'
    | 'info';

/** Organización (empresa) a la que pertenece el usuario. */
export type OrganizationSummary = {
    id: number | string;
    name: string;
    /** Razón social / RFC u otro dato secundario a mostrar. */
    legal_name?: string | null;
    rfc?: string | null;
    /** Zona horaria IANA (p. ej. "America/Mexico_City"). */
    timezone?: string | null;
    logo_url?: string | null;
    [key: string]: unknown;
};

/** Notificación resumida para la campana del encabezado. */
export type NotificationSummary = {
    id: number | string;
    title: string;
    body?: string | null;
    /** ISO 8601 UTC. */
    created_at: string;
    read_at?: string | null;
    url?: string | null;
    tone?: Tone;
};

/** Metadatos de paginación normalizados (ver `lib/pagination.ts`). */
export type PaginationMeta = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

/** Paginador de Laravel serializado (`->paginate()` en una prop Inertia). */
export type LaravelPaginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    [key: string]: unknown;
};

/** Colección de API Resource paginada (`Resource::collection($paginator)`). */
export type ResourcePaginator<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        [key: string]: unknown;
    };
    links?: Record<string, string | null>;
};

export type SortDirection = 'asc' | 'desc';

export type SortState = {
    key: string;
    direction: SortDirection;
};

/** Opción para SelectField / ComboboxField / filtros. */
export type SelectOption = {
    value: string;
    label: string;
    description?: string;
    disabled?: boolean;
    /** Texto adicional para búsqueda (combobox). */
    keywords?: string;
};

/** Rango de fechas civil (YYYY-MM-DD). */
export type DateRangeValue = {
    start: string | null;
    end: string | null;
};
