import { useSyncExternalStore } from 'react';

import type { PunchType } from '@/types/contracts';

/**
 * Resultado de la pantalla de rostro hacia Inicio:
 *  - match: continuar el marcaje con auth_method 'face_verification' y su id;
 *  - fallback: el colaborador eligió «Usar huella».
 */
export type FaceOutcome =
  | { kind: 'match'; type: PunchType; verificationId: string; expiresAt: string }
  | { kind: 'fallback'; type: PunchType };

let current: FaceOutcome | null = null;
const listeners = new Set<() => void>();

export const faceStore = {
  set(o: FaceOutcome | null) {
    current = o;
    listeners.forEach((l) => l());
  },
  get: () => current,
  subscribe(l: () => void) {
    listeners.add(l);
    return () => listeners.delete(l);
  },
};

export function useFaceOutcome(): FaceOutcome | null {
  return useSyncExternalStore(faceStore.subscribe, faceStore.get, faceStore.get);
}
