/**
 * Formateadores de presentación (es-MX). Sin lógica laboral: sólo convierten
 * valores ya calculados por el backend a texto legible.
 *
 * - Fechas/horas: el backend entrega ISO 8601 en UTC; aquí se convierten a la
 *   zona horaria IANA indicada (la del centro de trabajo) con `Intl`.
 * - Duraciones: minutos enteros.
 */

export const LOCALE = 'es-MX';

export type DateTimeFormat =
    | 'datetime'
    | 'datetime-long'
    | 'date'
    | 'date-long'
    | 'date-short'
    | 'time'
    | 'time-seconds'
    | 'weekday'
    | 'month-year';

const FORMAT_OPTIONS: Record<DateTimeFormat, Intl.DateTimeFormatOptions> = {
    datetime: {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    },
    'datetime-long': {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    },
    date: { day: '2-digit', month: 'short', year: 'numeric' },
    'date-long': {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    },
    'date-short': { day: '2-digit', month: '2-digit', year: 'numeric' },
    time: { hour: '2-digit', minute: '2-digit', hour12: false },
    'time-seconds': {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    },
    weekday: { weekday: 'long' },
    'month-year': { month: 'long', year: 'numeric' },
};

const formatterCache = new Map<string, Intl.DateTimeFormat>();

function getFormatter(
    format: DateTimeFormat,
    timeZone?: string | null,
    withZone = false,
): Intl.DateTimeFormat {
    const key = `${format}|${timeZone ?? ''}|${withZone ? 1 : 0}`;
    let formatter = formatterCache.get(key);

    if (!formatter) {
        try {
            formatter = new Intl.DateTimeFormat(LOCALE, {
                ...FORMAT_OPTIONS[format],
                timeZone: timeZone ?? undefined,
                timeZoneName: withZone ? 'short' : undefined,
            });
        } catch {
            // Zona horaria inválida: se usa la del navegador.
            formatter = new Intl.DateTimeFormat(LOCALE, {
                ...FORMAT_OPTIONS[format],
                timeZoneName: withZone ? 'short' : undefined,
            });
        }

        formatterCache.set(key, formatter);
    }

    return formatter;
}

/** Convierte ISO / Date / epoch-ms a Date, o null si es inválido. */
export function toDate(
    value: string | number | Date | null | undefined,
): Date | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    // Fecha civil sin hora: se interpreta a mediodía UTC para no
    // desplazar el día al formatear en zonas horarias de América.
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const civil = new Date(`${value}T12:00:00Z`);

        return Number.isNaN(civil.getTime()) ? null : civil;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** Formatea un instante UTC en la zona horaria indicada. */
export function formatDateTime(
    value: string | number | Date | null | undefined,
    options: {
        format?: DateTimeFormat;
        timeZone?: string | null;
        withZone?: boolean;
        fallback?: string;
    } = {},
): string {
    const date = toDate(value);

    if (!date) {
        return options.fallback ?? '—';
    }

    const isCivilDate =
        typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);

    return getFormatter(
        options.format ?? 'datetime',
        isCivilDate ? 'UTC' : options.timeZone,
        options.withZone ?? false,
    )
        .format(date)
        .replace(/\.(?=\s|$)/g, '');
}

/** Fecha civil "YYYY-MM-DD" → "17 nov 2026". */
export function formatDate(
    value: string | Date | null | undefined,
    format: Extract<
        DateTimeFormat,
        'date' | 'date-long' | 'date-short' | 'month-year' | 'weekday'
    > = 'date',
    timeZone?: string | null,
): string {
    return formatDateTime(value, { format, timeZone });
}

const relativeFormatter = new Intl.RelativeTimeFormat(LOCALE, {
    numeric: 'auto',
});

/** "hace 5 minutos", "ayer", "en 2 días". */
export function formatRelative(
    value: string | number | Date | null | undefined,
    now: Date = new Date(),
): string {
    const date = toDate(value);

    if (!date) {
        return '—';
    }

    const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 45) {
        return 'justo ahora';
    }

    const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['minute', 60],
        ['hour', 3600],
        ['day', 86400],
        ['week', 604800],
        ['month', 2629800],
        ['year', 31557600],
    ];

    let unit: Intl.RelativeTimeFormatUnit = 'minute';
    let divisor = 60;

    for (const [candidate, size] of units) {
        if (abs >= size) {
            unit = candidate;
            divisor = size;
        }
    }

    return relativeFormatter.format(Math.round(seconds / divisor), unit);
}

export type MinutesFormat = 'long' | 'short' | 'clock' | 'decimal';

/**
 * Minutos enteros → texto.
 * - long:    "7 h 30 min"
 * - short:   "7h 30m"
 * - clock:   "07:30"
 * - decimal: "7.5 h"
 */
export function formatMinutes(
    minutes: number | null | undefined,
    format: MinutesFormat = 'long',
    options: { signed?: boolean; fallback?: string } = {},
): string {
    if (minutes === null || minutes === undefined || Number.isNaN(minutes)) {
        return options.fallback ?? '—';
    }

    const total = Math.round(minutes);
    const negative = total < 0;
    const abs = Math.abs(total);
    const hours = Math.floor(abs / 60);
    const mins = abs % 60;
    const sign = negative ? '−' : options.signed && total > 0 ? '+' : '';

    switch (format) {
        case 'clock':
            return `${sign}${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;
        case 'decimal':
            return `${sign}${formatNumber(abs / 60, { maximumFractionDigits: 2 })} h`;
        case 'short':
            if (hours === 0) {
                return `${sign}${mins}m`;
            }

            return mins === 0
                ? `${sign}${hours}h`
                : `${sign}${hours}h ${mins}m`;
        case 'long':
        default:
            if (hours === 0) {
                return `${sign}${mins} min`;
            }

            return mins === 0
                ? `${sign}${hours} h`
                : `${sign}${hours} h ${mins} min`;
    }
}

export function formatNumber(
    value: number | null | undefined,
    options: Intl.NumberFormatOptions = {},
): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return new Intl.NumberFormat(LOCALE, options).format(value);
}

/** 0.1234 → "12.3 %" (si `isRatio`) o 12.34 → "12.3 %". */
export function formatPercent(
    value: number | null | undefined,
    options: { isRatio?: boolean; digits?: number; signed?: boolean } = {},
): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    const ratio = options.isRatio ? value : value / 100;

    return new Intl.NumberFormat(LOCALE, {
        style: 'percent',
        maximumFractionDigits: options.digits ?? 1,
        signDisplay: options.signed ? 'exceptZero' : 'auto',
    }).format(ratio);
}

/**
 * Importes: recibir SIEMPRE string/decimal del backend. Se formatea con
 * Intl sin aritmética en el cliente.
 */
export function formatCurrency(
    value: string | number | null | undefined,
    currency = 'MXN',
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const numeric = typeof value === 'string' ? Number(value) : value;

    if (Number.isNaN(numeric)) {
        return String(value);
    }

    return new Intl.NumberFormat(LOCALE, {
        style: 'currency',
        currency,
    }).format(numeric);
}

/** Bytes → "2.4 MB". */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let index = 0;

    while (value >= 1024 && index < units.length - 1) {
        value /= 1024;
        index++;
    }

    return `${formatNumber(value, { maximumFractionDigits: 1 })} ${units[index]}`;
}

/** Zona horaria del navegador. */
export function browserTimeZone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}
