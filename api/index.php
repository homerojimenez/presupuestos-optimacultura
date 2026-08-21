<?php
// Front controller de la API. Enrutado por .htaccess (Apache) o router.php (dev).

set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Error interno del servidor']);
  error_log('presupuestos: ' . $e->getMessage());
});

require __DIR__ . '/db.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/ai.php';

$config = get_config();
session_set_cookie_params([
  'lifetime' => 60 * 60 * 24 * 30,
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => !empty($config['secure_cookies']),
]);
session_start();

$pdo = db();
$user = current_user($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ---- imágenes subidas (protegidas por sesión) ----
if (preg_match('#^/uploads/(.+)$#', $path, $m)) {
  require_auth($user);
  $file = UPLOADS_DIR . '/' . basename($m[1]);
  if (!is_file($file)) { http_response_code(404); echo 'No encontrado'; exit; }
  $mimes = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml','gif'=>'image/gif'];
  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
  header('Cache-Control: private, max-age=300');
  readfile($file);
  exit;
}

// ---- auth ----
if ($path === '/api/login' && $method === 'POST') {
  $b = read_json_body();
  $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
  $stmt->execute([trim($b['username'] ?? '')]);
  $u = $stmt->fetch();
  if (!$u || !password_verify($b['password'] ?? '', $u['password_hash'])) {
    json_out(401, ['error' => 'Usuario o contraseña incorrectos']);
  }
  session_regenerate_id(true);
  $_SESSION['user_id'] = (int) $u['id'];
  json_out(200, ['user' => ['id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'], 'avatar' => $u['avatar'] ?? '']]);
}

if ($path === '/api/logout' && $method === 'POST') {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  json_out(200, ['ok' => true]);
}

if ($path === '/api/me' && $method === 'GET') {
  require_auth($user);
  json_out(200, ['user' => $user]);
}

if ($path === '/api/account/password' && $method === 'POST') {
  require_auth($user);
  $b = read_json_body();
  $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
  $stmt->execute([$user['id']]);
  $u = $stmt->fetch();
  if (!password_verify($b['currentPassword'] ?? '', $u['password_hash'])) {
    json_out(400, ['error' => 'La contraseña actual no es correcta']);
  }
  if (strlen($b['newPassword'] ?? '') < 6) {
    json_out(400, ['error' => 'La nueva contraseña debe tener al menos 6 caracteres']);
  }
  $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
      ->execute([password_hash($b['newPassword'], PASSWORD_DEFAULT), $user['id']]);
  json_out(200, ['ok' => true]);
}

// Foto de perfil del usuario: se guarda en /uploads y se referencia por URL.
if ($path === '/api/account/avatar' && $method === 'POST') {
  require_auth($user);
  $b = read_json_body();
  $avatar = $b['avatar'] ?? '';
  if (is_string($avatar) && str_starts_with($avatar, 'data:')) {
    $url = store_data_url($avatar);
    if (!$url) json_out(400, ['error' => 'Imagen no válida']);
    $avatar = $url;
  }
  // Borra el avatar anterior si era un fichero subido.
  $prev = $pdo->prepare('SELECT avatar FROM users WHERE id = ?');
  $prev->execute([$user['id']]);
  $old = $prev->fetch()['avatar'] ?? '';
  if (is_string($old) && str_starts_with($old, '/uploads/') && $old !== $avatar) {
    delete_uploads([basename($old) => true]);
  }
  $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$avatar, $user['id']]);
  json_out(200, ['avatar' => $avatar]);
}

// ---- usuarios (admin) ----
if ($path === '/api/users' && $method === 'GET') {
  require_auth($user);
  if ($user['role'] !== 'admin') json_out(403, ['error' => 'Solo administradores']);
  $rows = $pdo->query('SELECT id, username, role, avatar, created_at FROM users ORDER BY username')->fetchAll();
  foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
  json_out(200, ['users' => $rows]);
}

if ($path === '/api/users' && $method === 'POST') {
  require_auth($user);
  if ($user['role'] !== 'admin') json_out(403, ['error' => 'Solo administradores']);
  $b = read_json_body();
  $username = trim($b['username'] ?? '');
  $role = ($b['role'] ?? '') === 'admin' ? 'admin' : 'user';
  if ($username === '') json_out(400, ['error' => 'Usuario obligatorio']);
  if (strlen($b['password'] ?? '') < 6) json_out(400, ['error' => 'La contraseña debe tener al menos 6 caracteres']);
  $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
  $chk->execute([$username]);
  if ($chk->fetch()) json_out(409, ['error' => 'Ese usuario ya existe']);
  $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')
      ->execute([$username, password_hash($b['password'], PASSWORD_DEFAULT), $role, gmdate('c')]);
  json_out(201, ['user' => ['id' => (int) $pdo->lastInsertId(), 'username' => $username, 'role' => $role]]);
}

if (preg_match('#^/api/users/(\d+)$#', $path, $m) && $method === 'DELETE') {
  require_auth($user);
  if ($user['role'] !== 'admin') json_out(403, ['error' => 'Solo administradores']);
  $tid = (int) $m[1];
  if ($tid === (int) $user['id']) json_out(400, ['error' => 'No puedes eliminar tu propia cuenta']);
  $admins = (int) $pdo->query("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")->fetch()['n'];
  $t = $pdo->prepare('SELECT role FROM users WHERE id = ?');
  $t->execute([$tid]);
  $trow = $t->fetch();
  if (!$trow) json_out(404, ['error' => 'Usuario no encontrado']);
  if ($trow['role'] === 'admin' && $admins <= 1) json_out(400, ['error' => 'Debe quedar al menos un administrador']);
  $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$tid]);
  json_out(200, ['ok' => true]);
}

// ---- propuestas ----
if ($path === '/api/proposals' && $method === 'GET') {
  require_auth($user);
  $q = trim($_GET['q'] ?? '');
  $services = array_filter(array_map('trim', explode(',', $_GET['services'] ?? '')), fn($s) => $s !== '');
  $where = [];
  $args = [];
  // Un usuario normal solo ve su propio histórico; el administrador, el de todos.
  if (!is_admin($user)) { $where[] = 'owner_id = ?'; $args[] = $user['id']; }
  if ($q !== '') { $where[] = 'client_name LIKE ?'; $args[] = '%' . $q . '%'; }
  foreach ($services as $svc) { $where[] = 'services LIKE ?'; $args[] = '%,' . $svc . ',%'; }
  $sql = 'SELECT id, client_name, services, owner_id, owner_username, created_at, updated_at FROM proposals'
       . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
       . ' ORDER BY updated_at DESC LIMIT 500';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($args);
  json_out(200, ['proposals' => array_map('proposal_meta', $stmt->fetchAll())]);
}

if ($path === '/api/proposals' && $method === 'POST') {
  require_auth($user);
  $b = read_json_body();
  $payload = normalize_images($b['payload'] ?? []);
  $now = gmdate('c');
  $pdo->prepare('INSERT INTO proposals (client_name, services, payload, owner_id, owner_username, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([
        (string) ($payload['clientName'] ?? ''),
        services_string($payload),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $user['id'], $user['username'], $now, $now,
      ]);
  json_out(201, ['id' => (int) $pdo->lastInsertId(), 'payload' => $payload]);
}

if (preg_match('#^/api/proposals/(\d+)$#', $path, $m)) {
  $id = (int) $m[1];
  $stmt = $pdo->prepare('SELECT * FROM proposals WHERE id = ?');
  $stmt->execute([$id]);
  $existing = $stmt->fetch();

  if ($method === 'GET') {
    require_auth($user);
    if (!$existing) json_out(404, ['error' => 'Propuesta no encontrada']);
    require_proposal_access($user, $existing, 'abrir');
    json_out(200, array_merge(proposal_meta($existing), ['payload' => json_decode($existing['payload'], true)]));
  }

  if ($method === 'PUT') {
    require_auth($user);
    if (!$existing) json_out(404, ['error' => 'Propuesta no encontrada']);
    require_proposal_access($user, $existing, 'editar');
    $b = read_json_body();
    $payload = normalize_images($b['payload'] ?? []);
    $oldRefs = referenced_uploads(json_decode($existing['payload'], true) ?: []);
    $newRefs = referenced_uploads($payload);
    delete_uploads(array_diff_key($oldRefs, $newRefs));
    $pdo->prepare('UPDATE proposals SET client_name = ?, services = ?, payload = ?, updated_at = ? WHERE id = ?')
        ->execute([
          (string) ($payload['clientName'] ?? ''),
          services_string($payload),
          json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          gmdate('c'), $id,
        ]);
    json_out(200, ['id' => $id, 'payload' => $payload]);
  }

  if ($method === 'DELETE') {
    require_auth($user);
    if (!$existing) json_out(404, ['error' => 'Propuesta no encontrada']);
    require_proposal_access($user, $existing, 'eliminar');
    delete_uploads(referenced_uploads(json_decode($existing['payload'], true) ?: []));
    $pdo->prepare('DELETE FROM proposals WHERE id = ?')->execute([$id]);
    json_out(200, ['ok' => true]);
  }
}

// ---- asistente por IA ----
if ($path === '/api/ai/capabilities' && $method === 'GET') {
  require_auth($user);
  json_out(200, ai_capabilities());
}

if ($path === '/api/agent' && $method === 'POST') {
  require_auth($user);
  $b = read_json_body();
  $messages = is_array($b['messages'] ?? null) ? $b['messages'] : [];
  $proposal = is_array($b['proposal'] ?? null) ? $b['proposal'] : [];
  $result = run_agent($messages, $proposal);
  if (isset($result['error'])) json_out(400, $result);
  json_out(200, $result);
}

if ($path === '/api/transcribe' && $method === 'POST') {
  require_auth($user);
  $f = $_FILES['audio'] ?? null;
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    json_out(400, ['error' => 'No se recibió ningún audio']);
  }
  if (($f['size'] ?? 0) > 25 * 1024 * 1024) json_out(400, ['error' => 'El audio es demasiado grande (máx. 25 MB)']);
  $result = ai_transcribe($f['tmp_name'], $f['name'] ?? 'audio.webm', $f['type'] ?? 'audio/webm');
  if (isset($result['error'])) json_out(400, $result);
  json_out(200, $result);
}

if ($path === '/api/fetch-image' && $method === 'POST') {
  require_auth($user);
  $b = read_json_body();
  $url = trim($b['url'] ?? '');
  if ($url === '') json_out(400, ['error' => 'Falta la URL de la imagen']);
  $result = ai_fetch_image($url);
  if (isset($result['error'])) json_out(400, $result);
  json_out(200, $result);
}

json_out(404, ['error' => 'Endpoint no encontrado']);
