import { getDb } from '@/storage/db';

import type { KioskQueued, KioskQueueStore } from './queue';

/**
 * Almacén SQLite (base cifrada con SQLCipher) de la cola del kiosco. La tabla
 * se crea aquí para no tocar el esquema de la cola del colaborador.
 */
let ready: Promise<void> | null = null;

async function db() {
  const d = await getDb();
  if (!ready) {
    ready = d
      .execAsync(
        `CREATE TABLE IF NOT EXISTS kiosk_queue (
          client_uuid TEXT PRIMARY KEY NOT NULL,
          request TEXT NOT NULL,
          status TEXT NOT NULL,
          attempts INTEGER NOT NULL DEFAULT 0,
          next_attempt_at INTEGER NOT NULL,
          last_error TEXT,
          error TEXT,
          created_at INTEGER NOT NULL,
          synced_at INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_kiosk_queue_status ON kiosk_queue (status, next_attempt_at);`,
      )
      .catch((e) => {
        ready = null;
        throw e;
      });
  }
  await ready;
  return d;
}

type Row = Omit<KioskQueued, 'request' | 'error'> & { request: string; error: string | null };

const fromRow = (r: Row): KioskQueued => ({
  ...r,
  status: r.status as KioskQueued['status'],
  request: JSON.parse(r.request),
  error: r.error ? JSON.parse(r.error) : null,
});

const JSON_COLUMNS = new Set(['request', 'error']);

export const sqliteKioskStore: KioskQueueStore = {
  async insert(i) {
    const d = await db();
    await d.runAsync(
      `INSERT OR IGNORE INTO kiosk_queue (client_uuid, request, status, attempts, next_attempt_at, last_error, error, created_at, synced_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [i.client_uuid, JSON.stringify(i.request), i.status, i.attempts, i.next_attempt_at, i.last_error, i.error ? JSON.stringify(i.error) : null, i.created_at, i.synced_at],
    );
  },
  async update(id, patch) {
    const entries = Object.entries(patch).filter(([, v]) => v !== undefined);
    if (!entries.length) return;
    const d = await db();
    const values = entries.map(([k, v]) => (JSON_COLUMNS.has(k) ? (v === null ? null : JSON.stringify(v)) : v)) as (string | number | null)[];
    await d.runAsync(`UPDATE kiosk_queue SET ${entries.map(([k]) => `${k} = ?`).join(', ')} WHERE client_uuid = ?`, [...values, id]);
  },
  async get(id) {
    const r = await (await db()).getFirstAsync<Row>('SELECT * FROM kiosk_queue WHERE client_uuid = ?', [id]);
    return r ? fromRow(r) : null;
  },
  async all() {
    const rows = await (await db()).getAllAsync<Row>('SELECT * FROM kiosk_queue ORDER BY created_at ASC');
    return rows.map(fromRow);
  },
  async remove(id) {
    await (await db()).runAsync('DELETE FROM kiosk_queue WHERE client_uuid = ?', [id]);
  },
};
