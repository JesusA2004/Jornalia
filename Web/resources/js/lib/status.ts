import type { Tone } from '@/types';

export type StatusDefinition = {
    label: string;
    tone: Tone;
};

/**
 * Catálogo de estados → etiqueta en español + tono.
 * Etiquetas en femenino para solicitudes (rectificación, incidencia,
 * vacación…) y masculino para entidades (colaborador, dispositivo, marcaje).
 * Usa la prop `label` de StatusBadge cuando el género no coincida.
 */
export const STATUS_MAP: Record<string, StatusDefinition> = {
    // Flujo de solicitudes
    draft: { label: 'Borrador', tone: 'neutral' },
    pending: { label: 'Pendiente', tone: 'warning' },
    in_review: { label: 'En revisión', tone: 'info' },
    approved: { label: 'Aprobada', tone: 'success' },
    rejected: { label: 'Rechazada', tone: 'danger' },
    cancelled: { label: 'Cancelada', tone: 'neutral' },
    canceled: { label: 'Cancelada', tone: 'neutral' },
    expired: { label: 'Vencida', tone: 'neutral' },
    applied: { label: 'Aplicada', tone: 'primary' },

    // Marcajes
    accepted: { label: 'Aceptado', tone: 'success' },
    flagged: { label: 'Con alerta', tone: 'warning' },
    quarantined: { label: 'En cuarentena', tone: 'danger' },
    rectified: { label: 'Rectificado', tone: 'info' },
    on_time: { label: 'Puntual', tone: 'success' },
    late: { label: 'Retardo', tone: 'warning' },
    absent: { label: 'Falta', tone: 'danger' },
    early_leave: { label: 'Salida anticipada', tone: 'warning' },
    missing_punch: { label: 'Marcaje faltante', tone: 'danger' },
    rest_day: { label: 'Descanso', tone: 'neutral' },
    holiday: { label: 'Festivo', tone: 'brand' },

    // Entidades
    active: { label: 'Activo', tone: 'success' },
    inactive: { label: 'Inactivo', tone: 'neutral' },
    suspended: { label: 'Suspendido', tone: 'warning' },
    revoked: { label: 'Revocado', tone: 'danger' },
    blocked: { label: 'Bloqueado', tone: 'danger' },
    terminated: { label: 'Baja', tone: 'neutral' },
    invited: { label: 'Invitado', tone: 'info' },
    online: { label: 'En línea', tone: 'success' },
    offline: { label: 'Sin conexión', tone: 'neutral' },

    // Procesos
    queued: { label: 'En cola', tone: 'neutral' },
    processing: { label: 'Procesando', tone: 'info' },
    completed: { label: 'Completado', tone: 'success' },
    failed: { label: 'Fallido', tone: 'danger' },
    valid: { label: 'Íntegro', tone: 'success' },
    invalid: { label: 'Alterado', tone: 'danger' },
    compliant: { label: 'Cumple', tone: 'success' },
    non_compliant: { label: 'No cumple', tone: 'danger' },
    at_risk: { label: 'En riesgo', tone: 'warning' },
};

export function resolveStatus(
    status: string | null | undefined,
): StatusDefinition {
    if (!status) {
        return { label: 'Sin estado', tone: 'neutral' };
    }

    return (
        STATUS_MAP[status] ?? {
            label: status
                .replace(/[_-]+/g, ' ')
                .replace(/^\w/u, (c) => c.toUpperCase()),
            tone: 'neutral',
        }
    );
}

/** Clases de "insignia suave" por tono (fondo + texto + borde). */
export const TONE_SOFT_CLASSES: Record<Tone, string> = {
    neutral: 'bg-neutral-soft text-neutral-soft-foreground border-border',
    primary: 'bg-primary-soft text-primary-soft-foreground border-primary/15',
    brand: 'bg-brand-soft text-brand-soft-foreground border-brand/20',
    success: 'bg-success-soft text-success-soft-foreground border-success/20',
    warning: 'bg-warning-soft text-warning-soft-foreground border-warning/25',
    danger: 'bg-danger-soft text-danger-soft-foreground border-danger/20',
    info: 'bg-info-soft text-info-soft-foreground border-info/20',
};

/** Color sólido por tono (puntos, barras, iconos). */
export const TONE_DOT_CLASSES: Record<Tone, string> = {
    neutral: 'bg-subtle-foreground',
    primary: 'bg-primary',
    brand: 'bg-brand',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    info: 'bg-info',
};

/** Texto por tono. */
export const TONE_TEXT_CLASSES: Record<Tone, string> = {
    neutral: 'text-muted-foreground',
    primary: 'text-primary-soft-foreground',
    brand: 'text-brand-soft-foreground',
    success: 'text-success-soft-foreground',
    warning: 'text-warning-soft-foreground',
    danger: 'text-danger-soft-foreground',
    info: 'text-info-soft-foreground',
};

/** Contenedor de icono por tono (KpiTile, Timeline, EmptyState). */
export const TONE_ICON_CLASSES: Record<Tone, string> = {
    neutral: 'bg-surface-3 text-muted-foreground',
    primary: 'bg-primary-soft text-primary-soft-foreground',
    brand: 'bg-brand-soft text-brand-soft-foreground',
    success: 'bg-success-soft text-success-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    danger: 'bg-danger-soft text-danger-soft-foreground',
    info: 'bg-info-soft text-info-soft-foreground',
};

/** Variable CSS de color por tono (gráficas, estilos inline). */
export const TONE_CSS_VAR: Record<Tone, string> = {
    neutral: 'var(--subtle-foreground)',
    primary: 'var(--primary)',
    brand: 'var(--brand)',
    success: 'var(--success)',
    warning: 'var(--warning)',
    danger: 'var(--danger)',
    info: 'var(--info)',
};
