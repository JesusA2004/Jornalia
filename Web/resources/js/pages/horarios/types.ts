import type { LaravelPaginator, SelectOption } from '@/types';

export type ShiftKind = 'day' | 'night' | 'mixed';

export type ScheduleSegment = {
    day_index: number;
    ordinal: number;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
};

export type LegalCheck = {
    date: string;
    version: string;
    weekly_max: number;
    ok: boolean;
    /** Tramo futuro: sólo advierte, no bloquea. */
    future?: boolean;
};

export type ScheduleData = {
    id: number;
    name: string;
    code: string;
    type: string;
    shift_kind: ShiftKind;
    cycle_length_days: number;
    cycle_anchor_date: string | null;
    tolerance_minutes: number;
    flex_minutes: number;
    description: string | null;
    active: boolean;
    deleted_at: string | null;
    segments: ScheduleSegment[];
    weekly_minutes: number;
    checks: LegalCheck[];
    compliant_today: boolean;
    violations: string[];
    /** Pronóstico contra tramos futuros: advierte, no bloquea. */
    outlook: ScheduleOutlook;
    assigned?: number;
};

export type ScheduleOutlook = {
    adjust_before: string | null;
    adjust_weekly_max: number | null;
    compliant_until: string | null;
    warning: string | null;
};

export type LegalSummary = {
    today: string;
    version: string;
    verification_status: string;
    weekly_max: number;
    daily_max: Record<ShiftKind, number>;
    night_window: { start: string; end: string };
    upcoming: Array<{ date: string; version: string; weekly_max: number }>;
};

export type ScheduleTypeOption = SelectOption & { description: string };

export type LegalEvaluation = {
    weekly_minutes: number;
    checks: LegalCheck[];
    violations: Record<string, string[]>;
    outlook?: ScheduleOutlook;
};

export type AssignmentRow = {
    employee_id: number;
    name: string;
    employee_number: string;
    current_schedule: string | null;
    status: 'ready' | 'conflict' | 'unchanged';
    message: string | null;
};

export type AssignmentPreview = {
    schedule: { id: number; name: string };
    effective_from: string;
    legal_error: string[] | null;
    total: number;
    ready: number;
    rows: AssignmentRow[];
};

export type AssignmentMode = 'branch' | 'department' | 'selection';

export type AssignmentFilters = {
    schedule_id: string | null;
    effective_from: string | null;
    mode: AssignmentMode;
    branch_ids: string[];
    department_ids: string[];
    employee_ids: string[];
};

export type ScheduleExceptionRow = {
    id: number;
    date: string;
    employee: {
        id: number;
        name: string | null;
        employee_number: string | null;
    };
    is_rest_day: boolean;
    starts_at: string | null;
    ends_at: string | null;
    break_minutes: number;
    reason: string;
    reason_code: string | null;
    reason_label: string;
    status: ScheduleChangeStatus;
    created_by: string | null;
    created_by_id: number | null;
    approved_by: string | null;
    rejected_by: string | null;
    rejection_reason: string | null;
    notified_at: string | null;
};

export type ScheduleChangeStatus = 'pending' | 'approved' | 'rejected';

export type ScheduleExceptionPaginator = LaravelPaginator<ScheduleExceptionRow>;

export const DAY_NAMES = [
    'Lunes',
    'Martes',
    'Miércoles',
    'Jueves',
    'Viernes',
    'Sábado',
    'Domingo',
] as const;

export const DAY_SHORT = [
    'Lun',
    'Mar',
    'Mié',
    'Jue',
    'Vie',
    'Sáb',
    'Dom',
] as const;

export const SHIFT_KIND_LABELS: Record<ShiftKind, string> = {
    day: 'Diurna',
    night: 'Nocturna',
    mixed: 'Mixta',
};

/** Minutos efectivos de un segmento (cruza medianoche si fin ≤ inicio). */
export function segmentMinutes(
    segment: Pick<ScheduleSegment, 'starts_at' | 'ends_at' | 'break_minutes'>,
): number {
    const span = spanMinutes(segment.starts_at, segment.ends_at);

    return Math.max(0, span - (segment.break_minutes || 0));
}

export function spanMinutes(start: string, end: string): number {
    const a = toMinutes(start);
    const b = toMinutes(end);

    if (a === null || b === null) {
        return 0;
    }

    return b > a ? b - a : b + 1440 - a;
}

export function crossesMidnight(start: string, end: string): boolean {
    const a = toMinutes(start);
    const b = toMinutes(end);

    return a !== null && b !== null && b <= a;
}

export function toMinutes(value: string | null | undefined): number | null {
    if (!value || !/^\d{2}:\d{2}$/.test(value)) {
        return null;
    }

    const [h, m] = value.split(':').map(Number);

    return h * 60 + m;
}

export function dayLabel(index: number, cycle: number, short = false): string {
    if (cycle === 7) {
        return (short ? DAY_SHORT : DAY_NAMES)[index] ?? `Día ${index + 1}`;
    }

    return short ? `D${index + 1}` : `Día ${index + 1}`;
}

export function hoursLabel(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return m === 0 ? `${h} h` : `${h} h ${m} min`;
}
