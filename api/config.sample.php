<?php
// Copia este archivo a "config.php" y rellena tus datos.
// En SiteGround: crea una base de datos MySQL en Site Tools → MySQL y usa
// esas credenciales con driver 'mysql'.
return [
  'db' => [
    'driver' => 'mysql',              // 'mysql' (producción SiteGround) | 'sqlite' (pruebas locales)

    // --- MySQL (SiteGround) ---
    'mysql_host' => 'localhost',
    'mysql_name' => 'NOMBRE_DE_TU_BD',
    'mysql_user' => 'USUARIO_BD',
    'mysql_pass' => 'CONTRASEÑA_BD',
    'mysql_charset' => 'utf8mb4',

    // --- SQLite (solo pruebas locales) ---
    'sqlite_path' => __DIR__ . '/../data/presupuestos.sqlite',
  ],

  // Admin inicial: se crea la primera vez que arranca (si no hay usuarios).
  // Define aquí la contraseña; si la dejas vacía, se genera una y se escribe
  // en data/ADMIN_PASSWORD.txt para que la copies y luego borres ese archivo.
  'admin' => [
    'user' => 'admin',
    'password' => '',
  ],

  // Pon true cuando sirvas por HTTPS (cookies de sesión con flag Secure).
  'secure_cookies' => true,
];
