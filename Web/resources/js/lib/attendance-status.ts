import type { Tone } from '@/types';
import { TONE_DOT_CLASSES, TONE_SOFT_CLASSES } from './status';

/**
 * Leyenda única de estados de asistencia y eventos de calendario.
 *
 * Revisión RH (pantalla 08): los mismos colores en Asistencias, Calendario,
 * Incidencias y Reportes. Úsalo en lugar de definir paletas locales.
 */
export type AttendanceLegendKey =
    | 'present'
    | 'late'
    | 'absent'
    | 'omission'
    | 'rest'
    | 'holiday'
    | 'vacation'
    | 'leave'
    | 'incidence'
    | 'disability'
    | 'rectified'
    | 'shift'
    | 'none';

export type AttendanceLegendItem = {
    key: AttendanceLegendKey;
    label: string;
    tone: Tone;
};

/** Catálogo ordenado para pintar la leyenda. */
export const ATTENDANCE_LEGEND: AttendanceLegendItem[] = [
    { key: 'present', label: 'Presente', tone: 'success' },
    { key: 'late', label: 'Retardo', tone: 'warning' },
    { key: 'absent', label: 'Falta', tone: 'danger' },
    { key: 'omission', label: 'Omisión de checada', tone: 'danger' },
    { key: 'rectified', label: 'Rectificada', tone: 'info' },
    { key: 'shift', label: 'Turno', tone: 'primary' },
    { key: 'rest', label: 'Descanso', tone: 'neutral' },
    { key: 'holiday', label: 'Festivo', tone: 'brand' },
    { key: 'vacation', label: 'Vacaciones', tone: 'info' },
    { key: 'leave', label: 'Permiso', tone: 'success' },
    { key: 'incidence', label: 'Incidencia', tone: 'warning' },
    { key: 'disability', label: 'Incapacidad', tone: 'danger' },
];

const TONES = Object.fromEntries(
    ATTENDANCE_LEGEND.map((i) => [i.key, i.tone]),
) as Record<Exclude<AttendanceLegendKey, 'none'>, Tone>;

export function legendTone(key: AttendanceLegendKey): Tone {
    return key === 'none' ? 'neutral' : TONES[key];
}

/** Insignia suave (fondo + texto + borde) de un estado. */
export function legendSoftClass(key: AttendanceLegendKey): string {
    if (key === 'none') {
        return 'bg-transparent text-subtle-foreground border-dashed border-border';
    }

    return TONE_SOFT_CLASSES[TONES[key]];
}

/** Punto / barra sólida de un estado. */
export function legendDotClass(key: AttendanceLegendKey): string {
    return TONE_DOT_CLASSES[legendTone(key)];
}

/**
 * Estado de jornada (WorkdayStatus) → clave de leyenda. La omisión de checada
 * y la rectificación tienen prioridad sobre el estado calculado.
 */
export function workdayLegendKey(
    status: string | null | undefined,
    flags: { omission?: string | null; rectified?: boolean } = {},
): AttendanceLegendKey {
    if (flags.rectified) {
        return 'rectified';
    }
    if (flags.omission) {
        return 'omission';
    }

    switch (status) {
        case 'present':
        case 'rest_day_worked':
        case 'holiday_worked':
            return 'present';
        case 'late':
            return 'late';
        case 'absent':
            return 'absent';
        case 'incomplete':
            return 'omission';
        case 'rest_day':
            return 'rest';
        case 'holiday':
            return 'holiday';
        case 'vacation':
            return 'vacation';
        case 'leave':
            return 'leave';
        default:
            return 'none';
    }
}

/** Categoría de incidencia (catálogo) → clave de leyenda. */
export function incidenceLegendKey(
    category: string | null | undefined,
): AttendanceLegendKey {
    switch (category) {
        case 'disability':
            return 'disability';
        case 'leave':
            return 'leave';
        default:
            return 'incidence';
    }
}
