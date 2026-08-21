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

    // Carpeta de datos (imágenes subidas y, si usas SQLite, la base de datos).
    // IMPORTANTE: en SiteGround déjala FUERA de public_html. NGINX sirve los
    // ficheros estáticos sin pasar por Apache, así que el .htaccess no protege
    // nada que cuelgue del document root. Ruta típica en producción:
    //   /home/customer/www/TU-DOMINIO/private_data/presupuestos.sqlite
    // En local vale la de al lado.
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

  // --- Asistente por IA (opcional) -------------------------------------------
  // Cada capacidad se activa SOLA cuando su clave está presente. Si dejas una
  // clave vacía, esa función se desactiva y el chat lo avisa con claridad.
  // NUNCA se envían estas claves al navegador: solo se usan en el servidor.
  'ai' => [
    // Motor del agente. Pon la clave del proveedor que uses; si tienes varias,
    // manda 'provider' (en 'auto' se prefiere Anthropic > OpenAI > Gemini).
    'provider'          => 'auto',   // 'auto' | 'anthropic' | 'openai' | 'gemini'
    'anthropic_api_key' => '',
    'anthropic_model'   => 'claude-sonnet-4-5',
    'openai_api_key'    => '',
    'openai_model'      => 'gpt-4o',
    'gemini_api_key'    => '',        // https://aistudio.google.com/apikey
    'gemini_model'      => 'gemini-flash-latest',

    // Transcripción de voz. Con clave de OpenAI usa Whisper; si no hay OpenAI
    // pero sí Gemini, transcribe con Gemini (audio nativo).
    'whisper_model'     => 'whisper-1',

    // Búsqueda de imágenes de portada (uso comercial permitido). Clave gratuita.
    'pexels_api_key'    => '',        // https://www.pexels.com/api/
    'unsplash_access_key' => '',      // opcional: https://unsplash.com/developers

    // Logo desde la web del cliente: se extrae de la propia web (og:image,
    // apple-touch-icon, favicon). Opcional: token de logo.dev para más calidad.
    'logodev_token'     => '',        // opcional: https://logo.dev

    // Límites de seguridad para descargar imágenes externas al servidor.
    'max_image_bytes'   => 8388608,   // 8 MB
  ],
];
