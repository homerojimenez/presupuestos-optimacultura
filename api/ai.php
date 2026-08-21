<?php
// Asistente por IA: adaptadores de proveedor (Anthropic/OpenAI), transcripción
// de voz (Whisper), búsqueda de imágenes de portada (Pexels/Unsplash), logo del
// cliente desde su web y descarga segura de imágenes. TODO corre en el servidor:
// las claves de config.php nunca llegan al navegador.

// --- configuración y capacidades -------------------------------------------

function ai_config(): array {
  $c = get_config()['ai'] ?? [];
  return array_merge([
    'provider' => 'auto',
    'anthropic_api_key' => '',
    'anthropic_model' => 'claude-sonnet-4-5',
    'openai_api_key' => '',
    'openai_model' => 'gpt-4o',
    'gemini_api_key' => '',
    'gemini_model' => 'gemini-flash-latest',
    'whisper_model' => 'whisper-1',
    'pexels_api_key' => '',
    'unsplash_access_key' => '',
    'logodev_token' => '',
    'max_image_bytes' => 8388608,
  ], $c);
}

// Proveedor efectivo del agente según las claves disponibles.
function ai_provider(): ?string {
  $c = ai_config();
  $p = $c['provider'] ?? 'auto';
  if ($p === 'anthropic') return $c['anthropic_api_key'] !== '' ? 'anthropic' : null;
  if ($p === 'openai')    return $c['openai_api_key'] !== '' ? 'openai' : null;
  if ($p === 'gemini')    return $c['gemini_api_key'] !== '' ? 'gemini' : null;
  // auto: se prefiere Anthropic > OpenAI > Gemini según qué clave haya.
  if ($c['anthropic_api_key'] !== '') return 'anthropic';
  if ($c['openai_api_key'] !== '') return 'openai';
  if ($c['gemini_api_key'] !== '') return 'gemini';
  return null;
}

// Qué funciones están activas (se envía al frontend para adaptar el chat).
function ai_capabilities(): array {
  $c = ai_config();
  return [
    'agent' => ai_provider() !== null,
    'provider' => ai_provider(),
    'voice' => $c['openai_api_key'] !== '' || $c['gemini_api_key'] !== '',
    'coverSearch' => $c['pexels_api_key'] !== '' || $c['unsplash_access_key'] !== '',
    'logoFetch' => true, // extracción desde la web del cliente no requiere clave
  ];
}

// --- HTTP (curl) -------------------------------------------------------------

function ai_curl(string $method, string $url, array $headers, $body, int $timeout = 60): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 4,
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $resp = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  $err = curl_error($ch);
  return ['status' => $status, 'body' => $resp === false ? '' : $resp, 'ctype' => $ctype, 'error' => $err];
}

function ai_curl_json(string $method, string $url, array $headers, array $payload, int $timeout = 60): array {
  $headers[] = 'Content-Type: application/json';
  $r = ai_curl($method, $url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
  $r['json'] = json_decode($r['body'], true);
  return $r;
}

// --- guarda anti-SSRF --------------------------------------------------------
// Solo http/https y solo hacia IPs públicas (bloquea localhost, redes internas,
// metadatos de nube, etc.). Se aplica a TODA descarga de URL externa.

function ai_url_is_public(string $url): bool {
  $parts = parse_url($url);
  if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
  if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return false;
  $host = $parts['host'];
  $ips = @gethostbynamel($host);
  if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
  if (!$ips) return false;
  foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return false;
    }
  }
  return true;
}

// Descarga una imagen externa con la guarda y los límites de tamaño/tipo.
// Devuelve ['dataUrl'=>..., 'mime'=>...] o ['error'=>...].
function ai_fetch_image(string $url): array {
  if (!ai_url_is_public($url)) return ['error' => 'URL no permitida'];
  $max = (int) ai_config()['max_image_bytes'];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 4,
    CURLOPT_USERAGENT => 'OptimaPresupuestos/1.0',
    CURLOPT_BUFFERSIZE => 65536,
    CURLOPT_NOPROGRESS => false,
    CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($max) {
      return ($dlNow > $max || $dlTotal > $max) ? 1 : 0; // aborta si excede el límite
    },
  ]);
  $body = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $ctype = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
  if ($body === false || $status < 200 || $status >= 300) return ['error' => 'No se pudo descargar la imagen'];
  if (strlen($body) > $max) return ['error' => 'La imagen supera el tamaño máximo'];
  if (!str_starts_with($ctype, 'image/')) return ['error' => 'El enlace no es una imagen'];
  return ['dataUrl' => 'data:' . $ctype . ';base64,' . base64_encode($body), 'mime' => $ctype];
}

// --- transcripción de voz (Whisper / OpenAI) --------------------------------

function ai_transcribe(string $tmpPath, string $filename, string $mime): array {
  $c = ai_config();
  // Preferencia: Whisper (OpenAI) si hay clave; si no, Gemini (audio nativo).
  if ($c['openai_api_key'] !== '') {
    $post = [
      'model' => $c['whisper_model'] ?: 'whisper-1',
      'language' => 'es',
      'file' => new CURLFile($tmpPath, $mime ?: 'audio/webm', $filename ?: 'audio.webm'),
    ];
    $r = ai_curl('POST', 'https://api.openai.com/v1/audio/transcriptions',
      ['Authorization: Bearer ' . $c['openai_api_key']], $post, 90);
    $j = json_decode($r['body'], true);
    if ($r['status'] < 200 || $r['status'] >= 300) {
      return ['error' => $j['error']['message'] ?? 'Error al transcribir el audio'];
    }
    return ['text' => trim($j['text'] ?? '')];
  }
  if ($c['gemini_api_key'] !== '') {
    $bytes = @file_get_contents($tmpPath);
    if ($bytes === false) return ['error' => 'No se pudo leer el audio'];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($c['gemini_model']) . ':generateContent?key=' . urlencode($c['gemini_api_key']);
    $payload = ['contents' => [['role' => 'user', 'parts' => [
      ['inline_data' => ['mime_type' => $mime ?: 'audio/webm', 'data' => base64_encode($bytes)]],
      ['text' => 'Transcribe literalmente este audio en español. Devuelve solo el texto, sin comillas ni comentarios.'],
    ]]]];
    $r = ai_curl_json('POST', $url, [], $payload, 90);
    if ($r['status'] < 200 || $r['status'] >= 300) {
      return ['error' => $r['json']['error']['message'] ?? 'Error al transcribir el audio con Gemini'];
    }
    $text = '';
    foreach (($r['json']['candidates'][0]['content']['parts'] ?? []) as $p) {
      if (isset($p['text'])) $text .= $p['text'];
    }
    return ['text' => trim($text)];
  }
  return ['error' => 'Transcripción no configurada (falta la clave de OpenAI o Gemini en config.php)'];
}

// --- búsqueda de imágenes de portada ----------------------------------------

function ai_search_cover(string $query, string $orientation = 'landscape'): array {
  $c = ai_config();
  $out = [];
  if ($c['pexels_api_key'] !== '') {
    $url = 'https://api.pexels.com/v1/search?per_page=8&orientation=' . urlencode($orientation)
         . '&query=' . urlencode($query);
    $r = ai_curl('GET', $url, ['Authorization: ' . $c['pexels_api_key']], null, 30);
    $j = json_decode($r['body'], true);
    foreach (($j['photos'] ?? []) as $p) {
      $out[] = [
        'source' => 'pexels',
        'thumb' => $p['src']['medium'] ?? ($p['src']['small'] ?? ''),
        'url' => $p['src']['large2x'] ?? ($p['src']['large'] ?? ($p['src']['original'] ?? '')),
        'alt' => $p['alt'] ?? '',
        'credit' => $p['photographer'] ?? '',
        'link' => $p['url'] ?? '',
      ];
    }
  }
  if (!$out && $c['unsplash_access_key'] !== '') {
    $url = 'https://api.unsplash.com/search/photos?per_page=8&orientation=landscape&query=' . urlencode($query);
    $r = ai_curl('GET', $url, ['Authorization: Client-ID ' . $c['unsplash_access_key']], null, 30);
    $j = json_decode($r['body'], true);
    foreach (($j['results'] ?? []) as $p) {
      $out[] = [
        'source' => 'unsplash',
        'thumb' => $p['urls']['small'] ?? '',
        'url' => $p['urls']['regular'] ?? ($p['urls']['full'] ?? ''),
        'alt' => $p['alt_description'] ?? '',
        'credit' => $p['user']['name'] ?? '',
        'link' => $p['links']['html'] ?? '',
      ];
    }
  }
  return $out;
}

// --- logo del cliente desde su web ------------------------------------------

function ai_normalize_site(string $site): array {
  $site = trim($site);
  if ($site === '') return ['', ''];
  if (!preg_match('#^https?://#i', $site)) $site = 'https://' . ltrim($site, '/');
  $host = parse_url($site, PHP_URL_HOST) ?: '';
  return [$site, $host];
}

function ai_abs_url(string $ref, string $base): string {
  $ref = trim($ref);
  if ($ref === '') return '';
  if (preg_match('#^https?://#i', $ref)) return $ref;
  if (str_starts_with($ref, '//')) return 'https:' . $ref;
  $p = parse_url($base);
  $origin = $p['scheme'] . '://' . $p['host'];
  if (str_starts_with($ref, '/')) return $origin . $ref;
  return $origin . '/' . ltrim($ref, '/');
}

function ai_find_logo(string $site): array {
  [$url, $host] = ai_normalize_site($site);
  if ($host === '') return [];
  $cands = [];
  if (ai_url_is_public($url)) {
    $r = ai_curl('GET', $url, ['User-Agent: Mozilla/5.0 (compatible; OptimaPresupuestos/1.0)'], null, 20);
    $html = $r['body'] ?? '';
    if ($html) {
      $html = substr($html, 0, 400000);
      // og:image
      if (preg_match_all('#<meta[^>]+(?:property|name)=["\']og:image(?::secure_url)?["\'][^>]*>#i', $html, $mm)) {
        foreach ($mm[0] as $tag) {
          if (preg_match('#content=["\']([^"\']+)["\']#i', $tag, $c)) $cands[] = ai_abs_url($c[1], $url);
        }
      }
      // apple-touch-icon y <link rel=icon>
      if (preg_match_all('#<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]*>#i', $html, $mm)) {
        foreach ($mm[0] as $tag) {
          if (preg_match('#href=["\']([^"\']+)["\']#i', $tag, $c)) $cands[] = ai_abs_url($c[1], $url);
        }
      }
    }
  }
  // Servicios de logo/favicon como respaldo.
  $c = ai_config();
  if ($c['logodev_token'] !== '') {
    $cands[] = 'https://img.logo.dev/' . $host . '?token=' . urlencode($c['logodev_token']) . '&size=300&format=png';
  }
  $cands[] = 'https://logo.clearbit.com/' . $host . '?size=300';
  $cands[] = 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=256';
  // Únicos, en orden.
  $seen = [];
  $out = [];
  foreach ($cands as $u) {
    if ($u === '' || isset($seen[$u])) continue;
    $seen[$u] = true;
    $out[] = ['source' => 'web', 'thumb' => $u, 'url' => $u];
  }
  return $out;
}

// --- definición de herramientas del agente ----------------------------------

const AGENT_SERVICE_KEYS = [
  'ag_pater_roma', 'ag_digital', 'ag_clasica', 'ag_grupos',
  'ot_venta_asistida', 'ot_ticketpoint', 'vr_estaciones', 'videomapping',
  'diseno_grafico_web', 'diseno_editorial',
];

function agent_tools(): array {
  $svc = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => AGENT_SERVICE_KEYS]];
  // Esquema de descripciones: una propiedad string opcional por servicio
  // (mapa explícito, compatible con Gemini/OpenAI/Anthropic).
  $descProps = [];
  foreach (AGENT_SERVICE_KEYS as $k) $descProps[$k] = ['type' => 'string'];
  $descSchema = ['type' => 'object', 'properties' => $descProps];
  return [
    [
      'name' => 'update_proposal',
      'description' => 'Rellena o modifica campos de la propuesta comercial. Envía solo los campos que quieras cambiar. Las descripciones son un objeto {clave_de_servicio: texto}. No inventes precios: si el usuario no los dice, déjalos vacíos y pregúntale.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'clientName' => ['type' => 'string', 'description' => 'Nombre del cliente/espacio, p. ej. "Cripta de Gaudí".'],
          'services' => array_merge($svc, ['description' => 'Servicios incluidos en la oferta.']),
          'price' => ['type' => 'string', 'description' => 'Precio (p. ej. "0,65" o "12.500").'],
          'priceNote' => ['type' => 'string', 'description' => 'Nota del precio (por defecto "IVA no incluido").'],
          'duration' => ['type' => 'string', 'description' => 'Duración en meses (número).'],
          'additionalInfo' => ['type' => 'string', 'description' => 'Información adicional al final de la oferta (opción A).'],
          'docType' => ['type' => 'string', 'enum' => ['presupuesto', 'proyeccion'], 'description' => 'Tipo de documento.'],
          'descriptions' => array_merge($descSchema, ['description' => 'Descripción comercial por servicio incluido (una frase o dos por servicio).']),
          'optionBEnabled' => ['type' => 'boolean', 'description' => 'Activa una segunda opción (B).'],
          'optionBServices' => $svc,
          'optionBPrice' => ['type' => 'string'],
          'optionBPriceNote' => ['type' => 'string'],
          'optionBDuration' => ['type' => 'string'],
          'optionBAdditionalInfo' => ['type' => 'string', 'description' => 'Información adicional al final de la página de la opción B.'],
          'optionBDescriptions' => $descSchema,
        ],
      ],
    ],
    [
      'name' => 'search_cover_images',
      'description' => 'Busca fotos de portada (uso comercial permitido) según el tipo de espacio del cliente (museo, catedral, yacimiento, etc.). Devuelve candidatas que el usuario elige; NO se aplican solas.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'query' => ['type' => 'string', 'description' => 'Términos de búsqueda descriptivos del espacio (mejor en inglés para más resultados), p. ej. "gothic cathedral interior".'],
        ],
        'required' => ['query'],
      ],
    ],
    [
      'name' => 'find_client_logo',
      'description' => 'Obtiene candidatos de logo del cliente a partir de su web oficial. Devuelve candidatos que el usuario elige; NO se aplican solos.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'website' => ['type' => 'string', 'description' => 'Web o dominio del cliente, p. ej. "sagradafamilia.org".'],
        ],
        'required' => ['website'],
      ],
    ],
  ];
}

function agent_system_prompt(array $proposal): string {
  $snapshot = json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  $keys = implode(', ', AGENT_SERVICE_KEYS);
  return <<<PROMPT
Eres el asistente de ÓptimaCultura para redactar propuestas comerciales de servicios culturales (audioguías, ticketing, experiencias VR, videomapping y diseño). Ayudas al comercial a rellenar la propuesta hablando en español, de forma breve y concreta.

Servicios disponibles (usa exactamente estas claves): {$keys}.
- Audioguías: ag_pater_roma (infantil), ag_digital, ag_clasica, ag_grupos.
- Ticketing: ot_venta_asistida, ot_ticketpoint.
- Otros: vr_estaciones, videomapping, diseno_grafico_web, diseno_editorial.

Cómo trabajas:
- Cuando el usuario describa la propuesta, usa la herramienta update_proposal para rellenar los campos. Escribe descripciones claras y comerciales para cada servicio incluido.
- No inventes precios ni duraciones: si no los dice, déjalos vacíos y pregúntale.
- Para la imagen de portada, usa search_cover_images con una consulta que describa el tipo de espacio. Para el logo, usa find_client_logo con la web del cliente. Explica que las imágenes son propuestas y que el usuario elige (también puede adjuntar las suyas).
- Tras rellenar campos, confirma en una frase lo que has puesto. Pregunta lo que falte.

Estado actual de la propuesta (para tu contexto; las imágenes se indican como presentes/ausentes):
{$snapshot}
PROMPT;
}

// --- adaptadores de proveedor (formato neutro -> API) ------------------------

// Convierte el transcript neutro a mensajes de la API de Anthropic.
function llm_to_anthropic_messages(array $turns): array {
  $msgs = [];
  foreach ($turns as $t) {
    if ($t['role'] === 'user') {
      $msgs[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => (string) $t['text']]]];
    } elseif ($t['role'] === 'assistant') {
      $content = [];
      if (!empty($t['text'])) $content[] = ['type' => 'text', 'text' => $t['text']];
      foreach (($t['tool_calls'] ?? []) as $tc) {
        $content[] = ['type' => 'tool_use', 'id' => $tc['id'], 'name' => $tc['name'], 'input' => (object) $tc['input']];
      }
      $msgs[] = ['role' => 'assistant', 'content' => $content];
    } elseif ($t['role'] === 'tool') {
      $content = [];
      foreach ($t['results'] as $res) {
        $content[] = ['type' => 'tool_result', 'tool_use_id' => $res['id'], 'content' => $res['content']];
      }
      $msgs[] = ['role' => 'user', 'content' => $content];
    }
  }
  return $msgs;
}

function llm_to_openai_messages(array $turns, string $system): array {
  $msgs = [['role' => 'system', 'content' => $system]];
  foreach ($turns as $t) {
    if ($t['role'] === 'user') {
      $msgs[] = ['role' => 'user', 'content' => (string) $t['text']];
    } elseif ($t['role'] === 'assistant') {
      $m = ['role' => 'assistant', 'content' => $t['text'] ?? ''];
      if (!empty($t['tool_calls'])) {
        $m['tool_calls'] = array_map(fn($tc) => [
          'id' => $tc['id'], 'type' => 'function',
          'function' => ['name' => $tc['name'], 'arguments' => json_encode($tc['input'], JSON_UNESCAPED_UNICODE)],
        ], $t['tool_calls']);
      }
      $msgs[] = $m;
    } elseif ($t['role'] === 'tool') {
      foreach ($t['results'] as $res) {
        $msgs[] = ['role' => 'tool', 'tool_call_id' => $res['id'], 'content' => $res['content']];
      }
    }
  }
  return $msgs;
}

// Convierte el transcript neutro a "contents" de la API de Gemini.
function llm_to_gemini_contents(array $turns): array {
  $contents = [];
  foreach ($turns as $t) {
    if ($t['role'] === 'user') {
      $contents[] = ['role' => 'user', 'parts' => [['text' => (string) $t['text']]]];
    } elseif ($t['role'] === 'assistant') {
      $parts = [];
      if (!empty($t['text'])) $parts[] = ['text' => $t['text']];
      foreach (($t['tool_calls'] ?? []) as $tc) {
        $part = ['functionCall' => ['name' => $tc['name'], 'args' => (object) $tc['input']]];
        // Gemini 3.x exige devolver el thought_signature recibido en cada llamada.
        if (!empty($tc['signature'])) $part['thoughtSignature'] = $tc['signature'];
        $parts[] = $part;
      }
      if (!$parts) $parts[] = ['text' => ' '];
      $contents[] = ['role' => 'model', 'parts' => $parts];
    } elseif ($t['role'] === 'tool') {
      $parts = [];
      foreach ($t['results'] as $res) {
        $parts[] = ['functionResponse' => [
          'name' => $res['name'] ?? 'tool',
          'response' => ['result' => $res['content']],
        ]];
      }
      $contents[] = ['role' => 'user', 'parts' => $parts];
    }
  }
  return $contents;
}

// Una llamada al LLM. Devuelve ['text'=>..., 'tool_calls'=>[{id,name,input}], 'error'=>?].
function llm_call(string $provider, array $turns, string $system): array {
  $c = ai_config();
  $tools = agent_tools();
  if ($provider === 'gemini') {
    $fnDecls = array_map(fn($t) => [
      'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters'],
    ], $tools);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($c['gemini_model']) . ':generateContent?key=' . urlencode($c['gemini_api_key']);
    $payload = [
      'system_instruction' => ['parts' => [['text' => $system]]],
      'contents' => llm_to_gemini_contents($turns),
      'tools' => [['function_declarations' => $fnDecls]],
      'generationConfig' => ['maxOutputTokens' => 2048, 'temperature' => 0.4],
    ];
    $r = ai_curl_json('POST', $url, [], $payload, 90);
    if ($r['status'] < 200 || $r['status'] >= 300) {
      return ['error' => $r['json']['error']['message'] ?? 'Error del proveedor de IA (Gemini)'];
    }
    $text = '';
    $calls = [];
    $idx = 0;
    foreach (($r['json']['candidates'][0]['content']['parts'] ?? []) as $part) {
      if (isset($part['text'])) $text .= $part['text'];
      if (isset($part['functionCall'])) {
        $calls[] = [
          'id' => 'call_' . ($idx++),
          'name' => $part['functionCall']['name'] ?? '',
          'input' => (array) ($part['functionCall']['args'] ?? []),
          'signature' => $part['thoughtSignature'] ?? '',
        ];
      }
    }
    return ['text' => trim($text), 'tool_calls' => $calls];
  }
  if ($provider === 'anthropic') {
    $apiTools = array_map(fn($t) => [
      'name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters'],
    ], $tools);
    $payload = [
      'model' => $c['anthropic_model'],
      'max_tokens' => 2048,
      'system' => $system,
      'messages' => llm_to_anthropic_messages($turns),
      'tools' => $apiTools,
    ];
    $r = ai_curl_json('POST', 'https://api.anthropic.com/v1/messages', [
      'x-api-key: ' . $c['anthropic_api_key'],
      'anthropic-version: 2023-06-01',
    ], $payload, 90);
    if ($r['status'] < 200 || $r['status'] >= 300) {
      return ['error' => $r['json']['error']['message'] ?? 'Error del proveedor de IA (Anthropic)'];
    }
    $text = '';
    $calls = [];
    foreach (($r['json']['content'] ?? []) as $block) {
      if (($block['type'] ?? '') === 'text') $text .= $block['text'];
      if (($block['type'] ?? '') === 'tool_use') {
        $calls[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => (array) ($block['input'] ?? [])];
      }
    }
    return ['text' => trim($text), 'tool_calls' => $calls];
  }
  // OpenAI
  $apiTools = array_map(fn($t) => [
    'type' => 'function',
    'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters']],
  ], $tools);
  $payload = [
    'model' => $c['openai_model'],
    'messages' => llm_to_openai_messages($turns, $system),
    'tools' => $apiTools,
    'max_tokens' => 2048,
  ];
  $r = ai_curl_json('POST', 'https://api.openai.com/v1/chat/completions',
    ['Authorization: Bearer ' . $c['openai_api_key']], $payload, 90);
  if ($r['status'] < 200 || $r['status'] >= 300) {
    return ['error' => $r['json']['error']['message'] ?? 'Error del proveedor de IA (OpenAI)'];
  }
  $msg = $r['json']['choices'][0]['message'] ?? [];
  $calls = [];
  foreach (($msg['tool_calls'] ?? []) as $tc) {
    $calls[] = [
      'id' => $tc['id'],
      'name' => $tc['function']['name'] ?? '',
      'input' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
    ];
  }
  return ['text' => trim((string) ($msg['content'] ?? '')), 'tool_calls' => $calls];
}

// --- ejecución de herramientas ----------------------------------------------

// Filtra el input de update_proposal a campos y valores válidos, y lo acumula.
function agent_apply_update(array $input, array &$patch): string {
  $applied = [];
  $svcFilter = fn($arr) => array_values(array_intersect(
    is_array($arr) ? array_map('strval', $arr) : [], AGENT_SERVICE_KEYS));
  $descFilter = function ($obj) {
    $out = [];
    if (is_array($obj)) foreach ($obj as $k => $v) {
      if (in_array($k, AGENT_SERVICE_KEYS, true) && is_string($v)) $out[$k] = $v;
    }
    return $out;
  };
  $strFields = ['clientName', 'price', 'priceNote', 'duration', 'additionalInfo',
                'optionBPrice', 'optionBPriceNote', 'optionBDuration', 'optionBAdditionalInfo'];
  foreach ($strFields as $f) {
    if (isset($input[$f]) && is_string($input[$f])) { $patch[$f] = $input[$f]; $applied[] = $f; }
  }
  if (isset($input['docType']) && in_array($input['docType'], ['presupuesto', 'proyeccion'], true)) {
    $patch['docType'] = $input['docType']; $applied[] = 'docType';
  }
  if (isset($input['optionBEnabled'])) { $patch['optionBEnabled'] = (bool) $input['optionBEnabled']; $applied[] = 'optionBEnabled'; }
  if (isset($input['services'])) {
    $patch['services'] = $svcFilter($input['services']);
    // Si no se especifica optionAServices, la oferta A usa los mismos servicios.
    if (!isset($patch['optionAServices'])) $patch['optionAServices'] = $patch['services'];
    $applied[] = 'services';
  }
  if (isset($input['optionBServices'])) { $patch['optionBServices'] = $svcFilter($input['optionBServices']); $applied[] = 'optionBServices'; }
  if (isset($input['descriptions'])) {
    $patch['descriptions'] = array_merge($patch['descriptions'] ?? [], $descFilter($input['descriptions']));
    $applied[] = 'descriptions';
  }
  if (isset($input['optionBDescriptions'])) {
    $patch['optionBDescriptions'] = array_merge($patch['optionBDescriptions'] ?? [], $descFilter($input['optionBDescriptions']));
    $applied[] = 'optionBDescriptions';
  }
  return $applied ? ('Campos actualizados: ' . implode(', ', $applied) . '.') : 'No se aplicó ningún campo válido.';
}

// Ejecuta el bucle del agente. Devuelve reply + patch + candidatos de imagen.
function run_agent(array $messages, array $proposal): array {
  $provider = ai_provider();
  if (!$provider) {
    return ['error' => 'El agente no está configurado. Añade la clave de Anthropic u OpenAI en api/config.php.'];
  }
  $system = agent_system_prompt($proposal);

  // Transcript neutro a partir del historial recibido.
  $turns = [];
  foreach ($messages as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $text = is_string($m['content'] ?? '') ? $m['content'] : '';
    if ($text !== '') $turns[] = ['role' => $role, 'text' => $text];
  }
  if (!$turns) return ['error' => 'No hay mensaje que procesar.'];

  $patch = [];
  $cover = [];
  $logo = [];
  $reply = '';

  for ($i = 0; $i < 6; $i++) {
    $res = llm_call($provider, $turns, $system);
    if (isset($res['error'])) return ['error' => $res['error']];
    $turns[] = ['role' => 'assistant', 'text' => $res['text'], 'tool_calls' => $res['tool_calls']];
    if (empty($res['tool_calls'])) { $reply = $res['text']; break; }

    $toolResults = [];
    foreach ($res['tool_calls'] as $tc) {
      $name = $tc['name'];
      $input = $tc['input'];
      if ($name === 'update_proposal') {
        $msg = agent_apply_update($input, $patch);
        $toolResults[] = ['id' => $tc['id'], 'name' => $name, 'content' => $msg];
      } elseif ($name === 'search_cover_images') {
        $found = ai_search_cover((string) ($input['query'] ?? ''));
        $cover = array_merge($cover, $found);
        $content = $found
          ? ('Se han encontrado ' . count($found) . ' imágenes de portada; se muestran al usuario para que elija.')
          : 'No se encontraron imágenes (¿falta la clave de Pexels/Unsplash en config.php o la búsqueda no dio resultados?).';
        $toolResults[] = ['id' => $tc['id'], 'name' => $name, 'content' => $content];
      } elseif ($name === 'find_client_logo') {
        $found = ai_find_logo((string) ($input['website'] ?? ''));
        $logo = array_merge($logo, $found);
        $content = $found
          ? ('Se han encontrado ' . count($found) . ' candidatos de logo; se muestran al usuario para que elija.')
          : 'No se pudo obtener el logo de esa web.';
        $toolResults[] = ['id' => $tc['id'], 'name' => $name, 'content' => $content];
      } else {
        $toolResults[] = ['id' => $tc['id'], 'name' => $name, 'content' => 'Herramienta desconocida.'];
      }
    }
    $turns[] = ['role' => 'tool', 'results' => $toolResults];
    if ($reply === '' && !empty($res['text'])) $reply = $res['text'];
  }

  return [
    'reply' => $reply !== '' ? $reply : 'Listo.',
    'patch' => (object) $patch,
    'cover' => $cover,
    'logo' => $logo,
    'provider' => $provider,
  ];
}
