import type { Tone } from '@/types';
import type {
    RectificationSignatureStatus,
    RectificationStage,
    RectificationTab,
} from '@/types/operacion';

/** Pestañas de la bandeja en el orden del flujo (PDF pantalla 05). */
export const RECTIFICATION_TABS: { key: RectificationTab; label: string }[] = [
    { key: 'por_autorizar', label: 'Por autorizar' },
    { key: 'por_firmar', label: 'Por firmar colaborador' },
    { key: 'por_validar', label: 'Por validar RH' },
    { key: 'aplicadas', label: 'Aplicadas' },
    { key: 'rechazadas', label: 'Rechazadas' },
];

/** Pasos del flujo, para el indicador superior y el detalle. */
export const RECTIFICATION_STEPS = [
    { key: 'solicitud', label: 'Solicitud', hint: 'Colaborador o jefe' },
    { key: 'evidencia', label: 'Evidencia', hint: 'Obligatoria' },
    { key: 'autoriza', label: 'Autoriza', hint: 'Jefe inmediato' },
    { key: 'firma', label: 'Firma', hint: 'Colaborador (app)' },
    { key: 'valida', label: 'Valida', hint: 'Recursos Humanos' },
    { key: 'aplicada', label: 'Aplicada', hint: 'Original intacto' },
] as const;

export function stageTone(stage: RectificationStage): Tone {
    switch (stage) {
        case 'por_autorizar':
            return 'warning';
        case 'por_firmar':
            return 'info';
        case 'por_validar':
            return 'brand';
        case 'aplicada':
            return 'success';
        default:
            return 'danger';
    }
}

export const SIGNATURE_STATUS: Record<
    RectificationSignatureStatus,
    { label: string; tone: Tone }
> = {
    firmada: { label: 'Firmada', tone: 'success' },
    no_requerida: { label: 'Previa al flujo', tone: 'neutral' },
    pendiente: { label: 'Pendiente', tone: 'warning' },
    vencida: { label: 'Vencida', tone: 'danger' },
    no_aceptada: { label: 'No aceptada', tone: 'danger' },
    en_espera: { label: 'En espera', tone: 'neutral' },
};

/** Motivo de cierre legible. */
export function rejectionLabel(stage: string | null | undefined): string {
    switch (stage) {
        case 'vencida':
            return 'Plazo de firma vencido';
        case 'declinada':
            return 'El colaborador no aceptó';
        case 'cancelada':
            return 'Cancelada por el solicitante';
        case 'por_validar':
            return 'Rechazada por RH';
        case 'por_autorizar':
            return 'Rechazada por el jefe';
        default:
            return 'Rechazada';
    }
}

/** Índice del paso actual (0..5) según la etapa. */
export function stepIndex(stage: RectificationStage): number {
    switch (stage) {
        case 'por_autorizar':
            return 2;
        case 'por_firmar':
            return 3;
        case 'por_validar':
            return 4;
        case 'aplicada':
            return 6;
        default:
            return -1;
    }
}
