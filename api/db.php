<?php
// Capa de datos: PDO (MySQL en producción, SQLite en local), esquema y seed.

function get_config() {
  static $c = null;
  if ($c === null) $c = require __DIR__ . '/config.php';
  return $c;
}

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $cfg = get_config()['db'];
  $opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];
  if (($cfg['driver'] ?? 'mysql') === 'mysql') {
    $dsn = "mysql:host={$cfg['mysql_host']};dbname={$cfg['mysql_name']};charset={$cfg['mysql_charset']}";
    $pdo = new PDO($dsn, $cfg['mysql_user'], $cfg['mysql_pass'], $opts);
  } else {
    @mkdir(dirname($cfg['sqlite_path']), 0775, true);
    $pdo = new PDO('sqlite:' . $cfg['sqlite_path'], null, null, $opts);
    $pdo->exec('PRAGMA foreign_keys = ON');
  }
  init_schema($pdo);
  seed_admin($pdo);
  return $pdo;
}

function init_schema(PDO $pdo) {
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  if ($driver === 'mysql') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(190) UNIQUE NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      role VARCHAR(20) NOT NULL DEFAULT 'user',
      created_at VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proposals (
      id INT AUTO_INCREMENT PRIMARY KEY,
      client_name VARCHAR(255) NOT NULL DEFAULT '',
      services TEXT NOT NULL,
      payload LONGTEXT NOT NULL,
      owner_id INT NULL,
      owner_username VARCHAR(190) NOT NULL DEFAULT '',
      created_at VARCHAR(40) NOT NULL,
      updated_at VARCHAR(40) NOT NULL,
      INDEX idx_client (client_name(100)),
      INDEX idx_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT UNIQUE NOT NULL,
      password_hash TEXT NOT NULL,
      role TEXT NOT NULL DEFAULT 'user',
      created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proposals (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      client_name TEXT NOT NULL DEFAULT '',
      services TEXT NOT NULL DEFAULT ',,',
      payload TEXT NOT NULL,
      owner_id INTEGER,
      owner_username TEXT NOT NULL DEFAULT '',
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_client ON proposals(client_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_updated ON proposals(updated_at)");
  }
}

// Crea el admin la primera vez (sin usuarios). Si no hay contraseña en config,
// genera una y la escribe en data/ADMIN_PASSWORD.txt (bórrala tras copiarla).
function seed_admin(PDO $pdo) {
  $n = (int) $pdo->query('SELECT COUNT(*) AS n FROM users')->fetch()['n'];
  if ($n > 0) return;
  $cfg = get_config();
  $user = $cfg['admin']['user'] ?: 'admin';
  $provided = $cfg['admin']['password'] ?? '';
  $pass = $provided !== '' ? $provided : bin2hex(random_bytes(6));
  $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)');
  $stmt->execute([$user, password_hash($pass, PASSWORD_DEFAULT), 'admin', gmdate('c')]);
  if ($provided === '') {
    $dir = dirname(get_config()['db']['sqlite_path']);
    @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/ADMIN_PASSWORD.txt',
      "Usuario admin: {$user}\nContrasena: {$pass}\n\nCopia estos datos y borra este archivo.\n");
  }
}
