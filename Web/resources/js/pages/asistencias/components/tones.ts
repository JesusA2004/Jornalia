import type { Tone } from '@/types';

/** Tono visual por estado de jornada (la etiqueta viene del backend). */
export function workdayTone(status: string | null | undefined): Tone {
    switch (status) {
        case 'present':
            return 'success';
        case 'late':
        case 'incomplete':
            return 'warning';
        case 'absent':
            return 'danger';
        case 'holiday':
        case 'holiday_worked':
            return 'brand';
        case 'vacation':
        case 'leave':
            return 'info';
        case 'rest_day_worked':
            return 'primary';
        default:
            return 'neutral';
    }
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
