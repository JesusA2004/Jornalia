import type { Tone } from '@/types';

/** Etiquetas compartidas de las páginas de colaboradores. */
export const CONTRACT_LABELS: Record<string, string> = {
    indefinite: 'Tiempo indeterminado',
    fixed_term: 'Tiempo u obra determinada',
    seasonal: 'Por temporada',
    trial: 'Periodo a prueba',
    training: 'Capacitación inicial',
};

export const MODALITY_LABELS: Record<string, string> = {
    onsite: 'Presencial',
    remote: 'Teletrabajo',
    hybrid: 'Híbrida',
    field: 'En campo',
};

export const SHIFT_LABELS: Record<string, string> = {
    day: 'Diurna',
    night: 'Nocturna',
    mixed: 'Mixta',
};

export const WEEKDAYS = [
    'Lunes',
    'Martes',
    'Miércoles',
    'Jueves',
    'Viernes',
    'Sábado',
    'Domingo',
];

export const EMPLOYEE_STATUS: Record<string, { label: string; tone: Tone }> = {
    active: { label: 'Activo', tone: 'success' },
    inactive: { label: 'Inactivo', tone: 'neutral' },
    terminated: { label: 'Baja', tone: 'neutral' },
};

export const RUN_STATUS: Record<string, { label: string; tone: Tone }> = {
    completed: { label: 'Completada', tone: 'success' },
    partial: { label: 'Con conflictos', tone: 'warning' },
    simulated: { label: 'Simulación', tone: 'info' },
    failed: { label: 'Fallida', tone: 'danger' },
};

export const CONFLICT_LABELS: Record<string, string> = {
    duplicate: 'Duplicado',
    duplicate_in_file: 'Repetido en archivo',
    invalid: 'Dato inválido',
    unknown_reference: 'Referencia desconocida',
    source_error: 'Error de origen',
};

/** Minutos entre dos horas HH:MM (cruza medianoche si fin ≤ inicio). */
export function spanMinutes(start: string, end: string): number {
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    let diff = eh * 60 + em - (sh * 60 + sm);

    if (diff <= 0) {
        diff += 24 * 60;
    }

    return diff;
}

/** "2026-11-17" de hoy en la zona local del navegador. */
export function todayIso(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export function initialsOf(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}
