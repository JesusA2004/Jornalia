import { OctagonAlert, TriangleAlert, Info } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { Tone } from '@/types';

export type Severity = 'info' | 'warning' | 'critical';

export const SEVERITY_TONE: Record<Severity, Tone> = {
    critical: 'danger',
    warning: 'warning',
    info: 'info',
};

export const SEVERITY_ICON: Record<Severity, LucideIcon> = {
    critical: OctagonAlert,
    warning: TriangleAlert,
    info: Info,
};

export const SEVERITY_LABEL: Record<Severity, string> = {
    critical: 'Críticas',
    warning: 'Advertencias',
    info: 'Informativas',
};
