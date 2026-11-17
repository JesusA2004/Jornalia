import { useSyncExternalStore } from 'react';

import type { EvidenceFile } from '@/api/endpoints';

/**
 * Evidencia fotográfica capturada en la pantalla de cámara, compartida con el
 * formulario que la solicitó (rectificación o incidencia).
 */
let current: EvidenceFile | null = null;
const listeners = new Set<() => void>();

export const evidenceStore = {
  set(file: EvidenceFile | null) {
    current = file;
    listeners.forEach((l) => l());
  },
  get: () => current,
  subscribe(l: () => void) {
    listeners.add(l);
    return () => listeners.delete(l);
  },
};

export function useEvidence(): EvidenceFile | null {
  return useSyncExternalStore(evidenceStore.subscribe, evidenceStore.get, evidenceStore.get);
}
