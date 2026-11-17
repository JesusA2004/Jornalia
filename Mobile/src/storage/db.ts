import * as Crypto from 'expo-crypto';
import * as SecureStore from 'expo-secure-store';
import * as SQLite from 'expo-sqlite';

import type { QueuedPunch, QueueStore } from '@/features/offline/queue';

/**
 * Base local cifrada (expo-sqlite + SQLCipher). Contiene:
 *  - punch_queue: cola offline de marcajes firmados.
 *  - cache: últimas respuestas útiles sin conexión (política, jornada de hoy).
 *  - location_queue / location_state: cola de ubicación de trabajo.
 * La clave de cifrado vive en SecureStore. Si el binario no incluye SQLCipher
 * la base NO se abre (nunca se guardan ubicaciones ni marcajes en claro).
 * No almacena secretos de firma (esos viven en SecureStore).
 */

const DB_NAME = 'mrlana-v2.db';
const SCHEMA_VERSION = 2;
const KEY_NAME = 'db.cipher_key';

export class DatabaseEncryptionError extends Error {
  constructor() {
    super('Esta versión de la app no puede cifrar los datos locales.');
    this.name = 'DatabaseEncryptionError';
  }
}

async function cipherKey(): Promise<string> {
  const existing = await SecureStore.getItemAsync(KEY_NAME);
  if (existing) return existing;
  const bytes = await Crypto.getRandomBytesAsync(32);
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  await SecureStore.setItemAsync(KEY_NAME, hex);
  return hex;
}

let dbPromise: Promise<SQLite.SQLiteDatabase> | null = null;

export function getDb(): Promise<SQLite.SQLiteDatabase> {
  if (!dbPromise) {
    dbPromise = (async () => {
      const db = await SQLite.openDatabaseAsync(DB_NAME);
      const cipher = await db.getFirstAsync<{ cipher_version: string }>('PRAGMA cipher_version').catch(() => null);
      if (!cipher) {
        await db.closeAsync().catch(() => undefined);
        throw new DatabaseEncryptionError();
      }
      // Clave hexadecimal cruda (64 caracteres), generada localmente.
      await db.execAsync(`PRAGMA key = "x'${await cipherKey()}'"`);
      await migrate(db);
      return db;
    })().catch((e) => {
      dbPromise = null;
      throw e;
    });
  }
  return dbPromise;
}

async function migrate(db: SQLite.SQLiteDatabase): Promise<void> {
  await db.execAsync('PRAGMA journal_mode = WAL;');
  const row = await db.getFirstAsync<{ user_version: number }>('PRAGMA user_version');
  const current = row?.user_version ?? 0;
  if (current >= SCHEMA_VERSION) return;
  if (current < 1) {
    await db.execAsync(`
      CREATE TABLE IF NOT EXISTS punch_queue (
        client_uuid TEXT PRIMARY KEY NOT NULL,
        request TEXT NOT NULL,
        status TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at INTEGER NOT NULL,
        last_error TEXT,
        error TEXT,
        created_at INTEGER NOT NULL,
        synced_at INTEGER,
        server_punch TEXT
      );
      CREATE INDEX IF NOT EXISTS idx_punch_queue_status ON punch_queue (status, next_attempt_at);
      CREATE TABLE IF NOT EXISTS cache (
        key TEXT PRIMARY KEY NOT NULL,
        value TEXT NOT NULL,
        updated_at INTEGER NOT NULL
      );
    `);
  }
  if (current < 2) {
    await db.execAsync(`
      CREATE TABLE IF NOT EXISTS location_queue (
        user_id INTEGER NOT NULL,
        uuid TEXT NOT NULL,
        point TEXT NOT NULL,
        captured_at TEXT NOT NULL,
        batch_uuid TEXT,
        PRIMARY KEY (user_id, uuid)
      );
      CREATE INDEX IF NOT EXISTS idx_location_queue ON location_queue (user_id, captured_at);
      CREATE TABLE IF NOT EXISTS location_state (
        user_id INTEGER PRIMARY KEY NOT NULL,
        last_json TEXT,
        last_sent_at TEXT,
        last_error TEXT,
        last_error_at TEXT
      );
    `);
  }
  await db.execAsync(`PRAGMA user_version = ${SCHEMA_VERSION}`);
}

type Row = {
  client_uuid: string;
  request: string;
  status: string;
  attempts: number;
  next_attempt_at: number;
  last_error: string | null;
  error: string | null;
  created_at: number;
  synced_at: number | null;
  server_punch: string | null;
};

function fromRow(r: Row): QueuedPunch {
  return {
    client_uuid: r.client_uuid,
    request: JSON.parse(r.request),
    status: r.status as QueuedPunch['status'],
    attempts: r.attempts,
    next_attempt_at: r.next_attempt_at,
    last_error: r.last_error,
    error: r.error ? JSON.parse(r.error) : null,
    created_at: r.created_at,
    synced_at: r.synced_at,
    server_punch: r.server_punch ? JSON.parse(r.server_punch) : null,
  };
}

const JSON_COLUMNS = new Set(['error', 'server_punch']);

export const sqliteQueueStore: QueueStore = {
  async insert(item) {
    const db = await getDb();
    await db.runAsync(
      `INSERT OR IGNORE INTO punch_queue
        (client_uuid, request, status, attempts, next_attempt_at, last_error, error, created_at, synced_at, server_punch)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        item.client_uuid,
        JSON.stringify(item.request),
        item.status,
        item.attempts,
        item.next_attempt_at,
        item.last_error,
        item.error ? JSON.stringify(item.error) : null,
        item.created_at,
        item.synced_at,
        item.server_punch ? JSON.stringify(item.server_punch) : null,
      ],
    );
  },
  async update(clientUuid, patch) {
    const entries = Object.entries(patch).filter(([, v]) => v !== undefined);
    if (!entries.length) return;
    const db = await getDb();
    const sets = entries.map(([k]) => `${k} = ?`).join(', ');
    const values = entries.map(([k, v]) => (JSON_COLUMNS.has(k) ? (v === null ? null : JSON.stringify(v)) : v)) as (
      | string
      | number
      | null
    )[];
    await db.runAsync(`UPDATE punch_queue SET ${sets} WHERE client_uuid = ?`, [...values, clientUuid]);
  },
  async get(clientUuid) {
    const db = await getDb();
    const r = await db.getFirstAsync<Row>('SELECT * FROM punch_queue WHERE client_uuid = ?', [clientUuid]);
    return r ? fromRow(r) : null;
  },
  async all() {
    const db = await getDb();
    const rows = await db.getAllAsync<Row>('SELECT * FROM punch_queue ORDER BY created_at ASC');
    return rows.map(fromRow);
  },
  async remove(clientUuid) {
    const db = await getDb();
    await db.runAsync('DELETE FROM punch_queue WHERE client_uuid = ?', [clientUuid]);
  },
};

/** Caché de respuestas para mostrar información sin conexión. */
export async function cacheSet(key: string, value: unknown): Promise<void> {
  const db = await getDb();
  await db.runAsync('INSERT OR REPLACE INTO cache (key, value, updated_at) VALUES (?, ?, ?)', [
    key,
    JSON.stringify(value),
    Date.now(),
  ]);
}

export async function cacheGet<T>(key: string): Promise<{ value: T; updatedAt: number } | null> {
  const db = await getDb();
  const r = await db.getFirstAsync<{ value: string; updated_at: number }>('SELECT value, updated_at FROM cache WHERE key = ?', [key]);
  return r ? { value: JSON.parse(r.value) as T, updatedAt: r.updated_at } : null;
}

export async function cacheClear(): Promise<void> {
  const db = await getDb();
  await db.runAsync('DELETE FROM cache');
}
