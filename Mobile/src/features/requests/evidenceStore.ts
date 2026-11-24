import { useSyncExternalStore } from 'react';

import type { EvidenceFile } from '@/api/endpoints';

/**
 * Evidencias (cámara, galería o documento) del formulario abierto
 * (rectificación o incidencia). La pantalla de cámara agrega aquí su foto.
 */
export const MAX_EVIDENCE_FILES = 5;
export const MAX_EVIDENCE_BYTES = 10 * 1024 * 1024;
export const EVIDENCE_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/webp', 'application/pdf'] as const;

export type EvidenceProblem = 'too_many' | 'too_big' | 'bad_type';

export const EVIDENCE_PROBLEM_LABEL: Record<EvidenceProblem, string> = {
  too_many: `Máximo ${MAX_EVIDENCE_FILES} archivos.`,
  too_big: 'El archivo pesa más de 10 MB.',
  bad_type: 'Sólo fotos o PDF.',
};

/** Valida un archivo antes de agregarlo a la lista actual. */
export function checkEvidence(file: EvidenceFile, current: readonly EvidenceFile[]): EvidenceProblem | null {
  if (current.length >= MAX_EVIDENCE_FILES) return 'too_many';
  if (file.size != null && file.size > MAX_EVIDENCE_BYTES) return 'too_big';
  const type = file.type.toLowerCase();
  if (!type.startsWith('image/') && type !== 'application/pdf') return 'bad_type';
  return null;
}

let current: readonly EvidenceFile[] = [];
const listeners = new Set<() => void>();
const emit = () => listeners.forEach((l) => l());

export const evidenceStore = {
  /** Agrega un archivo; devuelve el problema si no se pudo. */
  add(file: EvidenceFile): EvidenceProblem | null {
    const problem = checkEvidence(file, current);
    if (problem) return problem;
    if (current.some((f) => f.uri === file.uri)) return null;
    current = [...current, file];
    emit();
    return null;
  },
  remove(uri: string) {
    current = current.filter((f) => f.uri !== uri);
    emit();
  },
  clear() {
    if (!current.length) return;
    current = [];
    emit();
  },
  get: (): readonly EvidenceFile[] => current,
  subscribe(l: () => void) {
    listeners.add(l);
    return () => listeners.delete(l);
  },
};

export function useEvidence(): readonly EvidenceFile[] {
  return useSyncExternalStore(evidenceStore.subscribe, evidenceStore.get, evidenceStore.get);
}
