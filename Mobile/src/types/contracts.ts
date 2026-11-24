/**
 * Tipos del contrato API v1. NO redefinir aquí: la fuente de verdad es
 * `packages/contracts/src/api.ts` (alias `@contracts/*` en tsconfig y Metro).
 *
 * Excepción temporal: el bloque «Revisión RH» declara los tipos de firma
 * electrónica (A2) que el backend ya expone (SignatureController,
 * RectificationController, RectificationResource) pero que aún no están en
 * `packages/contracts`. Todos los campos nuevos son opcionales/tolerantes para
 * no romper con servidores anteriores. En cuanto se publiquen en el contrato,
 * se reemplazan por re-exportaciones. Kiosco (B1), ubicación por puesto y
 * comunicados (B2) ya vienen del contrato.
 */
import type {
  IsoDateTime,
  Profile as BaseProfile,
  PunchType,
  Rectification as BaseRectification,
  Uuid,
} from '@contracts/api';

export * from '@contracts/api';

/* ─────────────────────────── Revisión RH (provisional) ─────────────────────────── */

export type Profile = BaseProfile & {
  /** true si el colaborador ya configuró su NIP de firma electrónica. */
  signature_pin_set?: boolean;
};

/** Etapa del flujo de 5 pasos de una rectificación (A2). */
export type RectificationStage = 'por_autorizar' | 'por_firmar' | 'por_validar' | 'aplicada' | 'rechazada';

/** Evidencia adjunta (el servidor no expone URL pública: sólo metadatos verificables). */
export interface RectificationEvidence {
  id: number | string;
  name: string;
  mime: string | null;
  size?: number | null;
  sha256?: string | null;
  /** Si algún día se expone una URL firmada. */
  url?: string | null;
}

/** Lado «Registro original» / «Propuesta» de una rectificación. */
export interface RectificationSide {
  type: PunchType | null;
  time: IsoDateTime | null;
  /** Resumen en texto que arma el servidor (p. ej. «Sin registro de salida»). */
  summary?: string | null;
  punch_uuid?: Uuid | null;
}

/** Ver Web/app/Http/Resources/V1/RectificationResource.php. */
export type Rectification = BaseRectification & {
  stage?: RectificationStage;
  stage_label?: string;
  /** Fecha límite para la firma del colaborador (3 días hábiles por omisión). */
  signature_due_at?: IsoDateTime | null;
  original?: RectificationSide | null;
  proposal?: RectificationSide | null;
  evidences?: RectificationEvidence[];
  /** Texto exacto que el colaborador firma (sólo en «por_firmar»). */
  signature_statement?: string | null;
  /** SHA-256 del contenido firmado; entra en la firma HMAC biométrica. */
  signature_payload_sha256?: string | null;
  can_sign?: boolean;
  signature?: { signed_at: IsoDateTime | null; method: SignatureMethod; hash: string } | null;
  is_late?: boolean;
  authorized_at?: IsoDateTime | null;
  applied_at?: IsoDateTime | null;
  rejection_stage?: string | null;
  rejection_reason?: string | null;
};

export type SignatureMethod = 'pin' | 'biometric';

/**
 * Firma electrónica (rectificación o documento). Con `method='biometric'` la
 * app desbloquea el secreto del dispositivo con la biometría del SO y envía:
 *   device_signature = hex(HMAC_SHA256(device_secret,
 *     "sign|{purpose}|{signable_id}|{signature_payload_sha256}|{device_uuid}"))
 * (ElectronicSignatureService::biometricCanonical). El dispositivo lo toma el
 * servidor del token; `device_uuid`/`device_time` son informativos.
 */
export interface SignRequest {
  method: SignatureMethod;
  pin?: string;
  device_signature?: string;
  device_uuid?: Uuid;
  device_time?: IsoDateTime;
}

/** POST /rectifications/{id}/decline */
export interface DeclineRequest {
  reason: string;
}

/** POST /profile/signature-pin */
export interface SignaturePinRequest {
  pin: string;
  current_pin?: string;
}

export type LegalDocumentCode = 'CONVENIO_REGISTRO' | 'AVISO_PRIVACIDAD' | (string & {});

/** GET /documents/pending — convenio y aviso pendientes de firma/aceptación. */
export interface PendingDocument {
  id: number;
  code: LegalDocumentCode;
  purpose?: 'registration_agreement' | 'privacy_notice';
  title: string;
  version: string;
  body_markdown: string;
  sha256?: string;
  /** Ruta autenticada (Bearer) al PDF. */
  pdf_url: string | null;
  published_at: IsoDateTime;
  /** Texto exacto de la declaración que se firma. */
  signature_statement?: string | null;
  signature_payload_sha256?: string | null;
}

/** POST /documents/{id}/sign → constancia. */
export interface DocumentSignResult {
  document_id: number;
  code: LegalDocumentCode;
  signed_at: IsoDateTime | null;
  method: SignatureMethod;
  hash: string;
}

/** Constancia guardada en el teléfono de un documento firmado (consulta posterior). */
export interface SignedDocument {
  id: number;
  code: LegalDocumentCode;
  title: string;
  version: string;
  body_markdown?: string | null;
  pdf_url: string | null;
  signed_at: IsoDateTime | null;
  signature_method?: SignatureMethod | null;
  hash?: string | null;
}

/** POST /announcements/{id}/read — confirmación de lectura de comunicados importantes. */
export type AnnouncementReadRequest = { device_uuid: Uuid };
