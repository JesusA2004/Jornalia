/**
 * Destino al tocar un aviso (push o bandeja). `data` trae sólo identificadores
 * en texto (contrato AppNotification/PushData). Lógica pura para pruebas.
 */
export type NotificationTarget =
  | { pathname: '/rectificaciones/[id]'; params: { id: string } }
  | { pathname: '/comprobante/[uuid]'; params: { uuid: string } }
  | { pathname: '/notificaciones'; params?: { focus?: string } }
  | { pathname: '/firmas' }
  | { pathname: '/dispositivos' }
  | { pathname: '/documentos' };

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function routeForNotification(kind: string | null | undefined, data: Record<string, unknown> | null | undefined): NotificationTarget {
  const d = data ?? {};
  const str = (k: string) => (typeof d[k] === 'string' || typeof d[k] === 'number' ? String(d[k]) : null);
  const subject = str('subject');
  const subjectId = str('subject_id');
  if ((subject === 'rectification' || (kind ?? '').startsWith('rectification.')) && subjectId && /^\d+$/.test(subjectId)) {
    return { pathname: '/rectificaciones/[id]', params: { id: subjectId } };
  }
  if ((kind ?? '').startsWith('rectification.')) return { pathname: '/firmas' };
  const punch = str('punch_uuid');
  if (punch && UUID.test(punch)) return { pathname: '/comprobante/[uuid]', params: { uuid: punch } };
  if (str('device_uuid') || (kind ?? '').startsWith('device.')) return { pathname: '/dispositivos' };
  if ((kind ?? '').startsWith('document.') || str('document_id')) return { pathname: '/documentos' };
  const id = str('notification_id');
  return id ? { pathname: '/notificaciones', params: { focus: id } } : { pathname: '/notificaciones' };
}

/** Acuse de marcaje (entrada/salida/descanso) emitido por el servidor. */
export function isPunchReceipt(n: { kind: string; category?: string; data?: Record<string, string> | null }): boolean {
  return n.kind.startsWith('attendance.punch') || n.kind.startsWith('attendance.receipt') || (n.category === 'attendance' && !!n.data?.punch_uuid);
}

/** Comunicado importante que pide confirmación de lectura (`data.requires_confirmation === '1'`). */
export function needsReadConfirmation(n: { kind: string; data?: Record<string, string> | null }): boolean {
  if (n.kind !== 'announcement') return false;
  const flag = n.data?.requires_confirmation;
  return flag === '1' || flag === 'true';
}
