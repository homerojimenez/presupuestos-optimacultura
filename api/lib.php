<?php
// Utilidades: respuestas JSON, cuerpo de petición, auth e imágenes.

define('UPLOADS_DIR', dirname(get_config()['db']['sqlite_path']) . '/uploads');

function json_out(int $status, $data): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function current_user(PDO $pdo) {
  if (empty($_SESSION['user_id'])) return null;
  $stmt = $pdo->prepare('SELECT id, username, role, avatar FROM users WHERE id = ?');
  $stmt->execute([$_SESSION['user_id']]);
  $u = $stmt->fetch();
  if (!$u) return null;
  $u['id'] = (int) $u['id'];
  return $u;
}

function require_auth($user) {
  if (!$user) json_out(401, ['error' => 'No autenticado']);
  return $user;
}

// ---- imágenes ----
const IMAGE_FIELDS = ['heroData', 'logoData'];
const EXT_BY_MIME = [
  'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
  'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/gif' => 'gif',
];

function store_data_url(string $dataUrl): ?string {
  if (!preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $m)) return null;
  $ext = EXT_BY_MIME[$m[1]] ?? 'bin';
  $bin = base64_decode($m[2], true);
  if ($bin === false) return null;
  @mkdir(UPLOADS_DIR, 0775, true);
  $name = time() . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
  file_put_contents(UPLOADS_DIR . '/' . $name, $bin);
  return '/uploads/' . $name;
}

// Convierte imágenes base64 del payload en ficheros y deja sus URLs.
function normalize_images(array $payload): array {
  foreach (IMAGE_FIELDS as $f) {
    if (isset($payload[$f]) && is_string($payload[$f]) && str_starts_with($payload[$f], 'data:')) {
      $url = store_data_url($payload[$f]);
      if ($url) $payload[$f] = $url;
    }
  }
  return $payload;
}

function referenced_uploads(array $payload): array {
  $set = [];
  foreach (IMAGE_FIELDS as $f) {
    $v = $payload[$f] ?? '';
    if (is_string($v) && str_starts_with($v, '/uploads/')) $set[basename($v)] = true;
  }
  return $set;
}

function delete_uploads(array $names): void {
  foreach ($names as $n => $_) { @unlink(UPLOADS_DIR . '/' . basename((string) $n)); }
}

function services_string(array $payload): string {
  $arr = (isset($payload['services']) && is_array($payload['services'])) ? $payload['services'] : [];
  return ',' . implode(',', $arr) . ',';
}

function proposal_meta(array $row): array {
  return [
    'id' => (int) $row['id'],
    'clientName' => $row['client_name'],
    'services' => array_values(array_filter(explode(',', $row['services']), fn($s) => $s !== '')),
    'owner' => $row['owner_username'],
    'ownerId' => $row['owner_id'] !== null ? (int) $row['owner_id'] : null,
    'createdAt' => $row['created_at'],
    'updatedAt' => $row['updated_at'],
  ];
}
