import * as SecureStore from 'expo-secure-store';
import { useEffect, useSyncExternalStore } from 'react';

/**
 * Indicador local «ya definiste tu NIP» (el perfil del servidor todavía no lo
 * expone). Si es incorrecto, el servidor lo corrige: pide `current_pin` o
 * responde «Primero define tu NIP de firma».
 */
const key = (userId: number) => `signature.pin_set.u${userId}`;
const state = new Map<number, boolean>();
const listeners = new Set<() => void>();
let version = 0;

export async function loadPinFlag(userId: number): Promise<boolean> {
  const v = (await SecureStore.getItemAsync(key(userId)).catch(() => null)) === '1';
  if (state.get(userId) !== v) {
    state.set(userId, v);
    version++;
    listeners.forEach((l) => l());
  }
  return v;
}

export async function setPinFlag(userId: number, value: boolean): Promise<void> {
  if (value) await SecureStore.setItemAsync(key(userId), '1').catch(() => undefined);
  else await SecureStore.deleteItemAsync(key(userId)).catch(() => undefined);
  state.set(userId, value);
  version++;
  listeners.forEach((l) => l());
}

/** null = aún no se sabe. */
export function usePinFlag(userId: number): boolean | null {
  useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => version,
  );
  useEffect(() => {
    void loadPinFlag(userId);
  }, [userId]);
  return state.get(userId) ?? null;
}
