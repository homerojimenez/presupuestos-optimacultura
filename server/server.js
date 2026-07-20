// Servidor HTTP nativo (node:http) para el constructor de propuestas.
// Sirve el frontend estático + una API REST con auth por sesión y BD SQLite.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { db, ROOT_DIR, UPLOADS_DIR, seedAdminIfEmpty, purgeExpiredSessions } from './db.js';
import {
  hashPassword, verifyPassword, newSessionToken,
  parseCookies, serializeCookie
} from './auth.js';

const PORT = Number(process.env.PORT) || 4000;
const SESSION_DAYS = 30;
const MAX_BODY = 25 * 1024 * 1024; // 25 MB (imágenes base64)
const SECURE_COOKIES = process.env.PRESUPUESTOS_SECURE_COOKIES === '1';

// ---------- utilidades HTTP ----------
function sendJson(res, status, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(body);
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) {
        reject(Object.assign(new Error('payload_too_large'), { status: 413 }));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => {
      if (!chunks.length) return resolve({});
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))); }
      catch { reject(Object.assign(new Error('invalid_json'), { status: 400 })); }
    });
    req.on('error', reject);
  });
}

// ---------- sesión / usuario ----------
function getSessionUser(req) {
  const token = parseCookies(req.headers.cookie).sid;
  if (!token) return null;
  const sess = db.prepare('SELECT * FROM sessions WHERE token = ?').get(token);
  if (!sess) return null;
  if (new Date(sess.expires_at).getTime() < Date.now()) {
    db.prepare('DELETE FROM sessions WHERE token = ?').run(token);
    return null;
  }
  return db.prepare('SELECT id, username, role FROM users WHERE id = ?').get(sess.user_id) || null;
}

function setSessionCookie(res, token) {
  res.setHeader('Set-Cookie', serializeCookie('sid', token, {
    httpOnly: true, sameSite: 'Lax', path: '/',
    maxAge: SESSION_DAYS * 24 * 3600, secure: SECURE_COOKIES
  }));
}

// ---------- imágenes ----------
const DATA_URL_RE = /^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/s;
const EXT_BY_MIME = {
  'image/png': 'png', 'image/jpeg': 'jpg', 'image/jpg': 'jpg',
  'image/webp': 'webp', 'image/svg+xml': 'svg', 'image/gif': 'gif'
};
const IMAGE_FIELDS = ['heroData', 'logoData'];

function storeDataUrl(dataUrl) {
  const m = DATA_URL_RE.exec(dataUrl);
  if (!m) return null;
  const ext = EXT_BY_MIME[m[1]] || 'bin';
  const buf = Buffer.from(m[2], 'base64');
  const name = `${Date.now()}-${crypto.randomBytes(6).toString('hex')}.${ext}`;
  fs.writeFileSync(path.join(UPLOADS_DIR, name), buf);
  return `/uploads/${name}`;
}

// Convierte las imágenes base64 del payload en ficheros y deja sus URLs.
function normalizeProposalImages(payload) {
  const out = { ...payload };
  for (const f of IMAGE_FIELDS) {
    const v = out[f];
    if (typeof v === 'string' && v.startsWith('data:')) {
      const url = storeDataUrl(v);
      if (url) out[f] = url;
    }
  }
  return out;
}

function referencedUploads(payload) {
  const set = new Set();
  for (const f of IMAGE_FIELDS) {
    const v = payload && payload[f];
    if (typeof v === 'string' && v.startsWith('/uploads/')) set.add(path.basename(v));
  }
  return set;
}

function deleteUploads(names) {
  for (const n of names) {
    try { fs.unlinkSync(path.join(UPLOADS_DIR, path.basename(n))); } catch { /* ignora */ }
  }
}

function servicesString(payload) {
  const arr = Array.isArray(payload && payload.services) ? payload.services : [];
  return ',' + arr.join(',') + ',';
}

// ---------- servido estático ----------
const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.webp': 'image/webp', '.svg': 'image/svg+xml', '.gif': 'image/gif',
  '.ico': 'image/x-icon'
};

function serveFile(res, absPath) {
  fs.stat(absPath, (err, st) => {
    if (err || !st.isFile()) { res.writeHead(404); res.end('No encontrado'); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(absPath).toLowerCase()] || 'application/octet-stream' });
    fs.createReadStream(absPath).pipe(res);
  });
}

// Resuelve un path público dentro de baseDir evitando path traversal.
function safeResolve(baseDir, urlPath) {
  const rel = decodeURIComponent(urlPath).replace(/^\/+/, '');
  const abs = path.join(baseDir, rel);
  const normalizedBase = path.resolve(baseDir) + path.sep;
  if (!path.resolve(abs).startsWith(normalizedBase) && path.resolve(abs) !== path.resolve(baseDir)) return null;
  return abs;
}

// ---------- helpers de negocio ----------
function requireAuth(res, user) {
  if (!user) { sendJson(res, 401, { error: 'No autenticado' }); return false; }
  return true;
}

function proposalRowToMeta(row) {
  return {
    id: row.id,
    clientName: row.client_name,
    services: row.services.split(',').filter(Boolean),
    owner: row.owner_username,
    ownerId: row.owner_id,
    createdAt: row.created_at,
    updatedAt: row.updated_at
  };
}

// ---------- API ----------
async function handleApi(req, res, url, user) {
  const { pathname } = url;
  const method = req.method;

  // --- auth ---
  if (pathname === '/api/login' && method === 'POST') {
    const body = await readJson(req);
    const username = String(body.username || '').trim();
    const row = db.prepare('SELECT * FROM users WHERE username = ?').get(username);
    if (!row || !verifyPassword(body.password || '', row.password_hash)) {
      return sendJson(res, 401, { error: 'Usuario o contraseña incorrectos' });
    }
    const token = newSessionToken();
    const now = new Date();
    const exp = new Date(now.getTime() + SESSION_DAYS * 24 * 3600 * 1000);
    db.prepare('INSERT INTO sessions (token, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
      .run(token, row.id, now.toISOString(), exp.toISOString());
    setSessionCookie(res, token);
    return sendJson(res, 200, { user: { id: row.id, username: row.username, role: row.role } });
  }

  if (pathname === '/api/logout' && method === 'POST') {
    const token = parseCookies(req.headers.cookie).sid;
    if (token) db.prepare('DELETE FROM sessions WHERE token = ?').run(token);
    res.setHeader('Set-Cookie', serializeCookie('sid', '', { httpOnly: true, sameSite: 'Lax', path: '/', maxAge: 0, secure: SECURE_COOKIES }));
    return sendJson(res, 200, { ok: true });
  }

  if (pathname === '/api/me' && method === 'GET') {
    if (!requireAuth(res, user)) return;
    return sendJson(res, 200, { user });
  }

  // --- cambio de contraseña propia ---
  if (pathname === '/api/account/password' && method === 'POST') {
    if (!requireAuth(res, user)) return;
    const body = await readJson(req);
    const row = db.prepare('SELECT * FROM users WHERE id = ?').get(user.id);
    if (!verifyPassword(body.currentPassword || '', row.password_hash)) {
      return sendJson(res, 400, { error: 'La contraseña actual no es correcta' });
    }
    if (String(body.newPassword || '').length < 6) {
      return sendJson(res, 400, { error: 'La nueva contraseña debe tener al menos 6 caracteres' });
    }
    db.prepare('UPDATE users SET password_hash = ? WHERE id = ?').run(hashPassword(body.newPassword), user.id);
    return sendJson(res, 200, { ok: true });
  }

  // --- gestión de usuarios (solo admin) ---
  if (pathname === '/api/users' && method === 'GET') {
    if (!requireAuth(res, user)) return;
    if (user.role !== 'admin') return sendJson(res, 403, { error: 'Solo administradores' });
    const rows = db.prepare('SELECT id, username, role, created_at FROM users ORDER BY username').all();
    return sendJson(res, 200, { users: rows });
  }

  if (pathname === '/api/users' && method === 'POST') {
    if (!requireAuth(res, user)) return;
    if (user.role !== 'admin') return sendJson(res, 403, { error: 'Solo administradores' });
    const body = await readJson(req);
    const username = String(body.username || '').trim();
    const role = body.role === 'admin' ? 'admin' : 'user';
    if (!username) return sendJson(res, 400, { error: 'Usuario obligatorio' });
    if (String(body.password || '').length < 6) return sendJson(res, 400, { error: 'La contraseña debe tener al menos 6 caracteres' });
    if (db.prepare('SELECT 1 FROM users WHERE username = ?').get(username)) {
      return sendJson(res, 409, { error: 'Ese usuario ya existe' });
    }
    const info = db.prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')
      .run(username, hashPassword(body.password), role, new Date().toISOString());
    return sendJson(res, 201, { user: { id: Number(info.lastInsertRowid), username, role } });
  }

  const userMatch = pathname.match(/^\/api\/users\/(\d+)$/);
  if (userMatch && method === 'DELETE') {
    if (!requireAuth(res, user)) return;
    if (user.role !== 'admin') return sendJson(res, 403, { error: 'Solo administradores' });
    const targetId = Number(userMatch[1]);
    if (targetId === user.id) return sendJson(res, 400, { error: 'No puedes eliminar tu propia cuenta' });
    const admins = db.prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'").get().n;
    const target = db.prepare('SELECT role FROM users WHERE id = ?').get(targetId);
    if (!target) return sendJson(res, 404, { error: 'Usuario no encontrado' });
    if (target.role === 'admin' && admins <= 1) return sendJson(res, 400, { error: 'Debe quedar al menos un administrador' });
    db.prepare('DELETE FROM users WHERE id = ?').run(targetId);
    return sendJson(res, 200, { ok: true });
  }

  // --- propuestas ---
  if (pathname === '/api/proposals' && method === 'GET') {
    if (!requireAuth(res, user)) return;
    const q = String(url.searchParams.get('q') || '').trim();
    const services = String(url.searchParams.get('services') || '').split(',').map((s) => s.trim()).filter(Boolean);
    const where = [];
    const args = [];
    if (q) { where.push('client_name LIKE ?'); args.push(`%${q}%`); }
    for (const svc of services) { where.push('services LIKE ?'); args.push(`%,${svc},%`); }
    const sql = 'SELECT id, client_name, services, owner_id, owner_username, created_at, updated_at FROM proposals'
      + (where.length ? ' WHERE ' + where.join(' AND ') : '')
      + ' ORDER BY updated_at DESC LIMIT 500';
    const rows = db.prepare(sql).all(...args);
    return sendJson(res, 200, { proposals: rows.map(proposalRowToMeta) });
  }

  if (pathname === '/api/proposals' && method === 'POST') {
    if (!requireAuth(res, user)) return;
    const body = await readJson(req);
    const payload = normalizeProposalImages(body.payload || {});
    const now = new Date().toISOString();
    const info = db.prepare(`INSERT INTO proposals
      (client_name, services, payload, owner_id, owner_username, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?)`)
      .run(String(payload.clientName || ''), servicesString(payload), JSON.stringify(payload),
           user.id, user.username, now, now);
    return sendJson(res, 201, { id: Number(info.lastInsertRowid), payload });
  }

  const propMatch = pathname.match(/^\/api\/proposals\/(\d+)$/);
  if (propMatch) {
    const id = Number(propMatch[1]);
    const existing = db.prepare('SELECT * FROM proposals WHERE id = ?').get(id);

    if (method === 'GET') {
      if (!requireAuth(res, user)) return;
      if (!existing) return sendJson(res, 404, { error: 'Propuesta no encontrada' });
      return sendJson(res, 200, { ...proposalRowToMeta(existing), payload: JSON.parse(existing.payload) });
    }

    if (method === 'PUT') {
      if (!requireAuth(res, user)) return;
      if (!existing) return sendJson(res, 404, { error: 'Propuesta no encontrada' });
      if (existing.owner_id !== user.id && user.role !== 'admin') {
        return sendJson(res, 403, { error: 'Solo el propietario o un administrador pueden editar' });
      }
      const body = await readJson(req);
      const payload = normalizeProposalImages(body.payload || {});
      // Limpia imágenes que ya no se referencian.
      const oldRefs = referencedUploads(JSON.parse(existing.payload));
      const newRefs = referencedUploads(payload);
      deleteUploads([...oldRefs].filter((n) => !newRefs.has(n)));
      db.prepare(`UPDATE proposals SET client_name = ?, services = ?, payload = ?, updated_at = ? WHERE id = ?`)
        .run(String(payload.clientName || ''), servicesString(payload), JSON.stringify(payload), new Date().toISOString(), id);
      return sendJson(res, 200, { id, payload });
    }

    if (method === 'DELETE') {
      if (!requireAuth(res, user)) return;
      if (!existing) return sendJson(res, 404, { error: 'Propuesta no encontrada' });
      if (existing.owner_id !== user.id && user.role !== 'admin') {
        return sendJson(res, 403, { error: 'Solo el propietario o un administrador pueden eliminar' });
      }
      deleteUploads(referencedUploads(JSON.parse(existing.payload)));
      db.prepare('DELETE FROM proposals WHERE id = ?').run(id);
      return sendJson(res, 200, { ok: true });
    }
  }

  return sendJson(res, 404, { error: 'Endpoint no encontrado' });
}

// ---------- router principal ----------
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const { pathname } = url;

  try {
    const user = getSessionUser(req);

    if (pathname.startsWith('/api/')) {
      return await handleApi(req, res, url, user);
    }

    // Imágenes subidas: protegidas por sesión (las <img> mandan la cookie).
    if (pathname.startsWith('/uploads/')) {
      if (!user) { res.writeHead(401); res.end('No autenticado'); return; }
      const abs = safeResolve(UPLOADS_DIR, pathname.slice('/uploads'.length));
      if (!abs) { res.writeHead(403); res.end('Prohibido'); return; }
      return serveFile(res, abs);
    }

    // Frontend estático (index.html + assets/).
    if (pathname === '/' || pathname === '/index.html') {
      return serveFile(res, path.join(ROOT_DIR, 'index.html'));
    }
    if (pathname.startsWith('/assets/')) {
      const abs = safeResolve(path.join(ROOT_DIR, 'assets'), pathname.slice('/assets'.length));
      if (!abs) { res.writeHead(403); res.end('Prohibido'); return; }
      return serveFile(res, abs);
    }

    res.writeHead(404); res.end('No encontrado');
  } catch (err) {
    const status = err && err.status ? err.status : 500;
    if (!res.headersSent) sendJson(res, status, { error: err.message || 'Error interno' });
    else res.end();
    if (status >= 500) console.error('Error en la petición', pathname, err);
  }
});

// ---------- arranque ----------
purgeExpiredSessions();
const seeded = seedAdminIfEmpty();
server.listen(PORT, () => {
  console.log(`\n  Constructor de propuestas — servidor en http://localhost:${PORT}`);
  if (seeded) {
    console.log('  ────────────────────────────────────────────────');
    console.log('  Usuario administrador creado:');
    console.log(`    usuario:    ${seeded.username}`);
    console.log(`    contraseña: ${seeded.password}`);
    if (seeded.generated) console.log('  (contraseña generada automáticamente — guárdala y cámbiala al entrar)');
    console.log('  ────────────────────────────────────────────────\n');
  }
});
