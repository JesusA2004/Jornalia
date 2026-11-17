import { ApiClient } from '@/api/client';
import { createEndpoints } from '@/api/endpoints';
import type { ApiRequestError } from '@/api/errors';
import { API_URL } from '@/config/env';
import { PunchQueue } from '@/features/offline/queue';
import { sqliteQueueStore } from '@/storage/db';
import { getSession } from '@/storage/secure';

/**
 * Instancias compartidas (cliente API, endpoints y cola offline).
 * El token vive en memoria mientras la app está abierta; en segundo plano se
 * lee de SecureStore.
 */
let memoryToken: string | null = null;
let authFailureListener: ((e: ApiRequestError) => void) | null = null;

export function setMemoryToken(token: string | null): void {
  memoryToken = token;
}

export function setAuthFailureListener(fn: ((e: ApiRequestError) => void) | null): void {
  authFailureListener = fn;
}

export const apiClient = new ApiClient({
  baseUrl: API_URL,
  getToken: async () => memoryToken ?? (await getSession())?.token ?? null,
  onAuthFailure: (e) => authFailureListener?.(e),
});

export const api = createEndpoints(apiClient);

export const punchQueue = new PunchQueue(sqliteQueueStore);
