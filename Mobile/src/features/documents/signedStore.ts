import { cacheGet, cacheSet } from '@/storage/db';
import type { DocumentSignResult, PendingDocument, SignedDocument } from '@/types/contracts';

const KEY = 'documents.signed.local';

/** Constancia local de un documento firmado (para consultarlo después en el teléfono). */
export function toSignedDocument(doc: PendingDocument, result: DocumentSignResult | null, method: SignedDocument['signature_method']): SignedDocument {
  return {
    id: doc.id,
    code: doc.code,
    title: doc.title,
    version: doc.version,
    body_markdown: doc.body_markdown,
    pdf_url: doc.pdf_url,
    signed_at: result?.signed_at ?? new Date().toISOString(),
    signature_method: result?.method ?? method ?? null,
    hash: result?.hash ?? null,
  };
}

/** Une listas (servidor primero) sin duplicar por id+versión. */
export function mergeSigned(server: readonly SignedDocument[], local: readonly SignedDocument[]): SignedDocument[] {
  const seen = new Set<string>();
  const out: SignedDocument[] = [];
  for (const d of [...server, ...local]) {
    const k = `${d.id}:${d.version}`;
    if (seen.has(k)) continue;
    seen.add(k);
    out.push(d);
  }
  return out.sort((a, b) => (Date.parse(b.signed_at ?? '') || 0) - (Date.parse(a.signed_at ?? '') || 0));
}

export async function readLocalSigned(): Promise<SignedDocument[]> {
  return (await cacheGet<SignedDocument[]>(KEY).catch(() => null))?.value ?? [];
}

export async function saveLocalSigned(doc: SignedDocument): Promise<void> {
  const list = await readLocalSigned();
  await cacheSet(KEY, mergeSigned([doc], list)).catch(() => undefined);
}
