import type { ConsentPurpose } from '@/crypto/signature';
import type { PendingDocument } from '@/types/contracts';

/**
 * Convenio de registro electrónico y aviso de privacidad: mientras haya
 * documentos vigentes sin firmar/aceptar, la app bloquea el marcaje (el
 * registro sólo hace prueba plena si fue acordado; LFT art. 132 fr. XXXIV).
 * Lógica pura para decidir el bloqueo con o sin conexión.
 */
export type FetchOutcome =
  | { kind: 'ok'; docs: PendingDocument[] }
  /** El servidor aún no expone el endpoint (404/405): no se bloquea. */
  | { kind: 'unsupported' }
  | { kind: 'network' }
  | { kind: 'error' };

export interface GateDecision {
  blocked: boolean;
  docs: PendingDocument[];
  /** La decisión proviene de la caché local (sin conexión). */
  fromCache: boolean;
}

const ORDER: Record<string, number> = { CONVENIO_REGISTRO: 0, AVISO_PRIVACIDAD: 1 };

/** Convenio primero, aviso después y el resto por fecha de publicación. */
export function sortDocuments(docs: readonly PendingDocument[]): PendingDocument[] {
  return [...docs].sort((a, b) => (ORDER[a.code] ?? 9) - (ORDER[b.code] ?? 9) || Date.parse(a.published_at) - Date.parse(b.published_at));
}

/**
 * - Respuesta del servidor: bloquea si hay pendientes.
 * - Sin conexión: usa lo último guardado (si estaba bloqueado, sigue bloqueado).
 * - Sin caché ni conexión, o endpoint inexistente: no bloquea (el servidor
 *   vuelve a validar cada marcaje).
 */
export function decideGate(outcome: FetchOutcome, cached: PendingDocument[] | null): GateDecision {
  if (outcome.kind === 'ok') return { blocked: outcome.docs.length > 0, docs: sortDocuments(outcome.docs), fromCache: false };
  if (outcome.kind === 'unsupported') return { blocked: false, docs: [], fromCache: false };
  if (cached && cached.length) return { blocked: true, docs: sortDocuments(cached), fromCache: true };
  return { blocked: false, docs: [], fromCache: cached !== null };
}

/** Propósito de la firma según el documento. */
export function purposeFor(doc: Pick<PendingDocument, 'code' | 'purpose'>): ConsentPurpose {
  if (doc.purpose) return doc.purpose;
  return doc.code === 'AVISO_PRIVACIDAD' ? 'privacy_notice' : 'registration_agreement';
}

/** Verbo visible: el aviso se «acepta», el convenio se «firma». */
export function actionLabel(code: string): 'Aceptar' | 'Firmar' {
  return code === 'AVISO_PRIVACIDAD' ? 'Aceptar' : 'Firmar';
}

/** Declaración que se firma. */
export function documentStatement(d: PendingDocument): string {
  if (d.signature_statement) return d.signature_statement;
  return d.code === 'AVISO_PRIVACIDAD'
    ? `Leí y acepto el «${d.title}» versión ${d.version}.`
    : `Leí y acepto el «${d.title}» versión ${d.version}, incluido el uso de mi celular y de mi ubicación para el registro de mi jornada.`;
}
