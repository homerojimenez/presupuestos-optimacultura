<?php
// Router SOLO para el servidor embebido de desarrollo:
//   php -S localhost:8000 router.php
// En producción (SiteGround/Apache) el enrutado lo hace .htaccess.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API e imágenes -> front controller PHP
if (preg_match('#^/(api|uploads)(/|$)#', $path)) {
  require __DIR__ . '/api/index.php';
  return true;
}

// Raíz -> index.html
if ($path === '/' || $path === '') {
  header('Content-Type: text/html; charset=utf-8');
  readfile(__DIR__ . '/index.html');
  return true;
}

// Cualquier otro fichero existente lo sirve el servidor embebido tal cual.
$file = __DIR__ . $path;
if (is_file($file)) return false;

http_response_code(404);
echo 'No encontrado';
return true;
