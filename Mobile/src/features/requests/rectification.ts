import type { Tone } from '@/components/ui/primitives';
import type { Rectification, RectificationSide, RectificationStage } from '@/types/contracts';

/**
 * Flujo de 5 pasos de una rectificación (revisión RH):
 * solicitud + evidencia → autorización del jefe → firma del colaborador →
 * validación RH → aplicación. Sin firma, el ajuste no se aplica y el marcaje
 * original nunca se modifica. Lógica pura y tolerante a servidores anteriores.
 */
export const STAGE_ORDER: RectificationStage[] = ['por_autorizar', 'por_firmar', 'por_validar', 'aplicada'];

export const STAGE_LABEL: Record<RectificationStage, string> = {
  por_autorizar: 'Por autorizar',
  por_firmar: 'Por firmar',
  por_validar: 'Por validar RH',
  aplicada: 'Aplicada',
  rechazada: 'Rechazada',
};

export const STAGE_TONE: Record<RectificationStage, Tone> = {
  por_autorizar: 'neutral',
  por_firmar: 'warning',
  por_validar: 'info',
  aplicada: 'success',
  rechazada: 'danger',
};

/** Etapa de la solicitud; sin `stage` (servidor anterior) se deriva de `status`. */
export function stageOf(r: Rectification): RectificationStage {
  if (r.stage) return r.stage;
  switch (r.status) {
    case 'approved':
      return 'aplicada';
    case 'rejected':
    case 'cancelled':
      return 'rechazada';
    default:
      return 'por_autorizar';
  }
}

/** Índice del paso para la barra de progreso (rechazada = -1). */
export function stageStep(stage: RectificationStage): number {
  return STAGE_ORDER.indexOf(stage);
}

function present(side: RectificationSide | null | undefined): side is RectificationSide {
  return !!side && (side.type !== null || side.time !== null || !!side.summary);
}

/**
 * «Registro original» y «Propuesta» (campos `original` / `proposal` del
 * servidor). Con servidores anteriores se arma la propuesta con
 * `proposed_type`/`proposed_time`.
 */
export function comparisonOf(r: Rectification): { original: RectificationSide[]; proposed: RectificationSide[] } {
  const original = present(r.original) ? [r.original] : [];
  let proposed = present(r.proposal) ? [r.proposal] : [];
  if (!proposed.length && (r.proposed_type || r.proposed_time)) {
    proposed = [{ type: r.proposed_type, time: r.proposed_time, punch_uuid: r.punch_uuid }];
  }
  return { original, proposed };
}

export interface DueInfo {
  dueAt: number;
  overdue: boolean;
  /** Horas restantes (redondeadas hacia abajo, ≥ 0). */
  hoursLeft: number;
  /** Menos de 24 h. */
  urgent: boolean;
}

/** Plazo de firma (sólo cuando la etapa es «por firmar» y hay fecha límite válida). */
export function dueInfo(r: Rectification, now: number): DueInfo | null {
  if (stageOf(r) !== 'por_firmar' || !r.signature_due_at) return null;
  const dueAt = Date.parse(r.signature_due_at);
  if (Number.isNaN(dueAt)) return null;
  const left = dueAt - now;
  return { dueAt, overdue: left <= 0, hoursLeft: Math.max(0, Math.floor(left / 3_600_000)), urgent: left > 0 && left < 24 * 3_600_000 };
}

/** Texto corto del plazo: «Vence en 2 d 5 h», «Vence en 3 h», «Venció». */
export function dueLabel(d: DueInfo): string {
  if (d.overdue) return 'Venció el plazo';
  const days = Math.floor(d.hoursLeft / 24);
  const hours = d.hoursLeft % 24;
  if (days > 0) return `Vence en ${days} d${hours ? ` ${hours} h` : ''}`;
  if (d.hoursLeft > 0) return `Vence en ${d.hoursLeft} h`;
  return 'Vence en menos de 1 h';
}

/** Solicitudes que esperan la firma del colaborador, la más urgente primero. */
export function pendingSignatures(list: readonly Rectification[]): Rectification[] {
  return list
    .filter((r) => stageOf(r) === 'por_firmar')
    .sort((a, b) => (Date.parse(a.signature_due_at ?? '') || Infinity) - (Date.parse(b.signature_due_at ?? '') || Infinity));
}

/** ¿Puede firmarse ahora? (etapa «por firmar» y plazo vigente). */
export function canSignNow(r: Rectification, now: number): boolean {
  if (stageOf(r) !== 'por_firmar') return false;
  if (r.can_sign === false) return false;
  const d = dueInfo(r, now);
  return !d?.overdue;
}

/** Declaración que se firma (la del servidor si viene; si no, una genérica). */
export function signatureStatement(r: Rectification, describe: (side: RectificationSide) => string): string {
  if (r.signature_statement) return r.signature_statement;
  const { original, proposed } = comparisonOf(r);
  const o = original.length ? original.map(describe).join(', ') : 'sin registro';
  const p = proposed.length ? proposed.map(describe).join(', ') : 'sin cambio';
  return `Manifiesto mi conformidad con la corrección del día ${r.date}: registro original ${o}; propuesta ${p}. Motivo: ${r.reason}. El marcaje original se conserva sin cambios.`;
}

/** Validación del motivo de rechazo. */
export function validateDeclineReason(reason: string): string | null {
  return reason.trim().length < 5 ? 'Escribe el motivo.' : null;
}
