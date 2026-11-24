import type { QueuedPunch } from '@/features/offline/queue';
import { cacheGet, cacheSet } from '@/storage/db';
import type { AppNotification, PunchReceipt, PunchType } from '@/types/contracts';

import { isPunchReceipt } from './routing';

/**
 * Acuses de entrada/salida en la bandeja de la app. Se muestran SIEMPRE,
 * aunque el SO no permita notificaciones: se arman con los acuses del
 * servidor, los comprobantes recibidos al marcar en línea y los marcajes
 * sincronizados desde la cola. Un marcaje sin respuesta del servidor aparece
 * como «Pendiente», nunca como confirmado.
 */
export type ReceiptState = 'confirmed' | 'review' | 'pending' | 'rejected';

export interface ReceiptEntry {
  key: string;
  type: PunchType | null;
  /** Hora oficial (recepción en servidor); null si aún no llega. */
  serverTime: string | null;
  /** Hora capturada en el teléfono. */
  deviceTime: string | null;
  state: ReceiptState;
  punchUuid: string | null;
  receiptCode: string | null;
  offline: boolean;
  notificationId: string | null;
  read: boolean;
}

export interface LocalReceipt {
  punch_uuid: string;
  type: PunchType;
  server_time: string;
  device_time: string | null;
  status: 'accepted' | 'flagged';
  receipt_code: string | null;
}

const KEY = 'acuses.local';
const MAX = 60;

export function receiptFromPunch(r: PunchReceipt): LocalReceipt {
  return {
    punch_uuid: r.punch.uuid,
    type: r.punch.type,
    server_time: r.punch.server_time,
    device_time: r.punch.device_time,
    status: r.punch.status,
    receipt_code: r.receipt_code,
  };
}

export async function readLocalReceipts(): Promise<LocalReceipt[]> {
  return (await cacheGet<LocalReceipt[]>(KEY).catch(() => null))?.value ?? [];
}

export async function saveLocalReceipt(r: LocalReceipt): Promise<void> {
  const list = (await readLocalReceipts()).filter((x) => x.punch_uuid !== r.punch_uuid);
  await cacheSet(KEY, [r, ...list].slice(0, MAX)).catch(() => undefined);
}

const PUNCH_TYPES: readonly string[] = ['clock_in', 'clock_out', 'break_start', 'break_end'];
const asType = (v: unknown): PunchType | null => (typeof v === 'string' && PUNCH_TYPES.includes(v) ? (v as PunchType) : null);

/** Une acuses sin duplicar (por uuid del marcaje) y los ordena del más reciente al más antiguo. */
export function buildReceipts(local: readonly LocalReceipt[], queue: readonly QueuedPunch[], notifications: readonly AppNotification[]): ReceiptEntry[] {
  const byKey = new Map<string, ReceiptEntry>();
  const put = (e: ReceiptEntry) => {
    const prev = byKey.get(e.key);
    if (!prev) {
      byKey.set(e.key, e);
      return;
    }
    // Gana el estado más firme (lo confirmado por el servidor sobre lo pendiente;
    // «en revisión» no se degrada); los datos faltantes se completan.
    const best = rank(e.state) > rank(prev.state) ? e : prev;
    byKey.set(e.key, {
      ...best,
      serverTime: best.serverTime ?? e.serverTime ?? prev.serverTime,
      deviceTime: prev.deviceTime ?? e.deviceTime,
      type: best.type ?? e.type ?? prev.type,
      receiptCode: best.receiptCode ?? e.receiptCode ?? prev.receiptCode,
      punchUuid: best.punchUuid ?? e.punchUuid ?? prev.punchUuid,
      offline: prev.offline || e.offline,
      notificationId: e.notificationId ?? prev.notificationId,
      // «Leído» lo define el aviso del servidor (si existe); lo local cuenta como leído.
      read: e.notificationId ? e.read : prev.notificationId ? prev.read : e.read && prev.read,
    });
  };
  for (const q of queue) {
    const sp = q.server_punch;
    put({
      key: sp?.uuid ?? q.client_uuid,
      type: q.request.type,
      serverTime: q.status === 'synced' ? (sp?.server_time ?? null) : null,
      deviceTime: q.request.device_time,
      state: q.status === 'synced' ? (sp?.status === 'flagged' ? 'review' : 'confirmed') : q.status === 'rejected' ? 'rejected' : 'pending',
      punchUuid: sp?.uuid ?? null,
      receiptCode: null,
      offline: true,
      notificationId: null,
      read: true,
    });
  }
  for (const l of local) {
    put({
      key: l.punch_uuid,
      type: l.type,
      serverTime: l.server_time,
      deviceTime: l.device_time,
      state: l.status === 'flagged' ? 'review' : 'confirmed',
      punchUuid: l.punch_uuid,
      receiptCode: l.receipt_code,
      offline: false,
      notificationId: null,
      read: true,
    });
  }
  for (const n of notifications) {
    if (!isPunchReceipt(n)) continue;
    const uuid = n.data.punch_uuid ?? null;
    put({
      key: uuid ?? `n:${n.id}`,
      type: asType(n.data.type ?? n.data.punch_type),
      serverTime: n.data.server_time ?? n.created_at,
      deviceTime: n.data.device_time ?? null,
      state: 'confirmed',
      punchUuid: uuid,
      receiptCode: n.data.receipt_code ?? null,
      offline: false,
      notificationId: n.id,
      read: n.read_at !== null,
    });
  }
  const time = (e: ReceiptEntry) => Date.parse(e.serverTime ?? e.deviceTime ?? '') || 0;
  return [...byKey.values()].sort((a, b) => time(b) - time(a));
}

function rank(s: ReceiptState): number {
  // «En revisión» (aceptado con anomalías) no se degrada a «confirmado» por un aviso genérico.
  return s === 'review' ? 3 : s === 'confirmed' ? 2 : s === 'rejected' ? 1 : 0;
}
