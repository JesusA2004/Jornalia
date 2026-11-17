import type { LucideIcon } from '@lucide/vue';
import type { Tone } from './app';

/** Elemento de <KeyValueGrid>. */
export type KeyValueItem = {
    /** Identificador; habilita el slot `#value-<key>`. */
    key: string;
    label: string;
    value?: string | number | null;
    /** Ocupa toda la fila. */
    full?: boolean;
    /** Fuente monoespaciada (folios, hashes, RFC). */
    mono?: boolean;
    hint?: string;
};

/** Elemento de <StatList>. */
export type StatListItem = {
    key: string;
    label: string;
    value: string | number;
    hint?: string;
    tone?: Tone;
    icon?: LucideIcon;
    /** 0–100: dibuja una barra de proporción. */
    progress?: number;
};

/** Elemento de <Timeline>. */
export type TimelineItem = {
    id: string | number;
    title: string;
    description?: string | null;
    /** ISO 8601 UTC. */
    at?: string | null;
    /** Zona IANA para mostrar `at` (centro de trabajo). */
    timezone?: string | null;
    tone?: Tone;
    icon?: LucideIcon;
    /** Quién realizó la acción. */
    actor?: string | null;
    /** Texto pequeño adicional (dispositivo, IP, folio…). */
    meta?: string | null;
};

/** Columna de <DataTable>. */
export type DataTableColumn<T> = {
    /** Identificador y clave de ordenamiento enviada al servidor. */
    key: string;
    label: string;
    /** Permite ordenar por esta columna (parámetros `sort`/`direction`). */
    sortable?: boolean;
    /** Clave de orden distinta de `key` (p. ej. "employees.last_name"). */
    sortKey?: string;
    align?: 'left' | 'center' | 'right';
    /** Clases para <th>/<td> (ancho, etc.). Ej.: 'w-40'. */
    class?: string;
    /** Oculta la columna por debajo de este breakpoint. */
    hideBelow?: 'sm' | 'md' | 'lg' | 'xl';
    /** Valor de la celda si no se usa el slot `#cell-<key>`. */
    accessor?: (row: T) => unknown;
    /** Celdas numéricas/horas: alinea a la derecha con cifras tabulares. */
    numeric?: boolean;
    /**
     * Vista de tarjetas en móvil: `title` (encabezado de la tarjeta; por
     * defecto la primera columna), `full` (ocupa toda la fila) o `hidden`.
     */
    mobile?: 'title' | 'full' | 'hidden';
};

/** Serie de las gráficas (ChartArea / ChartBar / ChartDonut). */
export type ChartSeries = {
    /** Clave del dato en cada fila. */
    key: string;
    label: string;
    /** Color CSS. Default: var(--chart-n) en orden. */
    color?: string;
};

/** Paso de la ayuda "?" (<HelpSheet>). */
export type HelpStep = {
    title: string;
    /** Una o dos líneas. */
    text?: string;
    icon?: LucideIcon;
};
