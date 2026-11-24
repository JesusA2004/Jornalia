import { ApiRequestError } from '@/api/errors';
import { api } from '@/services/container';
import type { Rectification } from '@/types/contracts';

import { pendingSignatures } from './rectification';

/**
 * Detalle de una rectificación. Si el servidor aún no expone
 * GET /rectifications/{id} (404/405), se busca en las primeras páginas de la lista.
 */
export async function fetchRectification(id: number): Promise<Rectification> {
  try {
    return await api.rectifications.show(id);
  } catch (e) {
    if (!(e instanceof ApiRequestError) || (e.status !== 404 && e.status !== 405)) throw e;
  }
  for (let page = 1; page <= 5; page++) {
    const r = await api.rectifications.list(page);
    const found = r.data.find((x) => x.id === id);
    if (found) return found;
    if (!r.meta || page >= r.meta.last_page) break;
  }
  throw new ApiRequestError(404, { code: 'not_found', message: 'No se encontró la solicitud.' });
}

/** Solicitudes que esperan tu firma (primera página filtrada por etapa). */
export async function fetchPendingSignatures(): Promise<Rectification[]> {
  const r = await api.rectifications.list(1, 'por_firmar');
  return pendingSignatures(r.data);
}
