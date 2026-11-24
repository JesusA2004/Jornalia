import { legendTone, workdayLegendKey } from '@/lib/attendance-status';
import type { Tone } from '@/types';

/**
 * Tono visual por estado de jornada (la etiqueta viene del backend). Usa la
 * leyenda compartida (`lib/attendance-status`) para conservar los mismos colores
 * en Asistencias, Calendario, Incidencias y Reportes.
 */
export function workdayTone(
    status: string | null | undefined,
    flags: { omission?: string | null; rectified?: boolean } = {},
): Tone {
    return legendTone(workdayLegendKey(status, flags));
}

export function punchStatusTone(status: string | null | undefined): Tone {
    return status === 'flagged' ? 'warning' : 'success';
}

export function approvalTone(status: string | null | undefined): Tone {
    switch (status) {
        case 'approved':
            return 'success';
        case 'pending':
            return 'warning';
        case 'rejected':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function severityTone(severity: string | null | undefined): Tone {
    switch (severity) {
        case 'critical':
            return 'danger';
        case 'warning':
            return 'warning';
        default:
            return 'info';
    }
}

/** "2026-11-17" → Date local sin desfase (mediodía UTC). */
export function civil(date: string): Date {
    return new Date(`${date}T12:00:00Z`);
}

const shortDay = new Intl.DateTimeFormat('es-MX', {
    weekday: 'short',
    timeZone: 'UTC',
});
const dayMonth = new Intl.DateTimeFormat('es-MX', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

export function weekdayShort(date: string): string {
    return shortDay.format(civil(date)).replace('.', '');
}

export function dayMonthShort(date: string): string {
    return dayMonth.format(civil(date)).replace('.', '');
}
