// Autenticación: hashing de contraseñas (scrypt + salt), tokens de sesión y
// utilidades de cookies. Sin dependencias externas: solo node:crypto.
import crypto from 'node:crypto';

const SCRYPT_KEYLEN = 64;

// Devuelve una cadena "scrypt$<saltHex>$<hashHex>" segura de almacenar.
export function hashPassword(password) {
  const salt = crypto.randomBytes(16);
  const hash = crypto.scryptSync(String(password), salt, SCRYPT_KEYLEN);
  return `scrypt$${salt.toString('hex')}$${hash.toString('hex')}`;
}

// Comparación en tiempo constante contra el hash almacenado.
export function verifyPassword(password, stored) {
  try {
    const [scheme, saltHex, hashHex] = String(stored).split('$');
    if (scheme !== 'scrypt' || !saltHex || !hashHex) return false;
    const salt = Buffer.from(saltHex, 'hex');
    const expected = Buffer.from(hashHex, 'hex');
    const actual = crypto.scryptSync(String(password), salt, expected.length);
    return actual.length === expected.length && crypto.timingSafeEqual(actual, expected);
  } catch {
    return false;
  }
}

export function newSessionToken() {
  return crypto.randomBytes(32).toString('base64url');
}

export function parseCookies(header) {
  const out = {};
  if (!header) return out;
  for (const part of header.split(';')) {
    const i = part.indexOf('=');
    if (i < 0) continue;
    const k = part.slice(0, i).trim();
    if (!k) continue;
    out[k] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

export function serializeCookie(name, value, opts = {}) {
  let s = `${name}=${encodeURIComponent(value)}`;
  if (opts.maxAge != null) s += `; Max-Age=${opts.maxAge}`;
  s += `; Path=${opts.path || '/'}`;
  if (opts.httpOnly) s += '; HttpOnly';
  if (opts.sameSite) s += `; SameSite=${opts.sameSite}`;
  if (opts.secure) s += '; Secure';
  return s;
}
