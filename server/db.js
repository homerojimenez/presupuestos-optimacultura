// Capa de datos: SQLite nativo (node:sqlite), esquema y seed del admin inicial.
import { DatabaseSync } from 'node:sqlite';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { hashPassword } from './auth.js';

const SERVER_DIR = path.dirname(fileURLToPath(import.meta.url));
export const ROOT_DIR = path.join(SERVER_DIR, '..');
export const DATA_DIR = path.join(ROOT_DIR, 'data');
export const UPLOADS_DIR = path.join(DATA_DIR, 'uploads');
const DB_PATH = path.join(DATA_DIR, 'presupuestos.db');

fs.mkdirSync(UPLOADS_DIR, { recursive: true });

export const db = new DatabaseSync(DB_PATH);
db.exec('PRAGMA journal_mode = WAL;');
db.exec('PRAGMA foreign_keys = ON;');

db.exec(`
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL
  );

  CREATE TABLE IF NOT EXISTS sessions (
    token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  );

  CREATE TABLE IF NOT EXISTS proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_name TEXT NOT NULL DEFAULT '',
    services TEXT NOT NULL DEFAULT ',,',
    payload TEXT NOT NULL,
    owner_id INTEGER,
    owner_username TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
  );

  CREATE INDEX IF NOT EXISTS idx_proposals_client ON proposals(client_name);
  CREATE INDEX IF NOT EXISTS idx_proposals_updated ON proposals(updated_at);
`);

// Crea un admin la primera vez (sin usuarios). Si no se define
// PRESUPUESTOS_ADMIN_PASSWORD, genera una contraseña aleatoria y la devuelve
// para mostrarla una única vez por consola.
export function seedAdminIfEmpty() {
  const { n } = db.prepare('SELECT COUNT(*) AS n FROM users').get();
  if (n > 0) return null;
  const username = process.env.PRESUPUESTOS_ADMIN_USER || 'admin';
  const provided = process.env.PRESUPUESTOS_ADMIN_PASSWORD;
  const password = provided || crypto.randomBytes(9).toString('base64url');
  db.prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')
    .run(username, hashPassword(password), 'admin', new Date().toISOString());
  return { username, password, generated: !provided };
}

// Borra sesiones caducadas (mantenimiento ligero al arrancar).
export function purgeExpiredSessions() {
  db.prepare('DELETE FROM sessions WHERE expires_at < ?').run(new Date().toISOString());
}
