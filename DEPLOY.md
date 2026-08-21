# Despliegue en SiteGround (GoGeek) — presupuestos.optimacultura.es

Backend **PHP + MySQL** (nativo en SiteGround). El frontend (`index.html` + `assets/`)
se sirve estático y la API vive en `api/` enrutada por `.htaccess`.

## 1. Crear la base de datos MySQL
En **Site Tools → Databases → MySQL**:
1. Crea una base de datos (p. ej. `optima_presupuestos`).
2. Crea un usuario y contraseña y **asígnalo a esa base de datos** con todos los permisos.
3. Anota: nombre de BD, usuario, contraseña y host (normalmente `localhost`).

## 2. Crear el subdominio
En **Site Tools → Domain → Subdomains**: crea `presupuestos` sobre `optimacultura.es`.
Apunta su carpeta (document root), p. ej. `.../presupuestos.optimacultura.es/`.

## 3. Subir los archivos
Sube al document root del subdominio **todo el proyecto excepto** lo local:

Incluir:
- `index.html`
- `assets/`
- `api/` (todo menos `config.php`, que no existe aún en el repo)
- `.htaccess`

**No subir:** `server/` (ya no existe), `data/` (se crea sola), `router.php`
(solo para pruebas locales), `.git/`.

## 4. Configurar credenciales
1. Copia `api/config.sample.php` a `api/config.php`.
2. Edita `api/config.php`:
   ```php
   'driver' => 'mysql',
   'mysql_name' => 'TU_BD',
   'mysql_user' => 'TU_USUARIO',
   'mysql_pass' => 'TU_CONTRASEÑA',
   'admin' => [ 'user' => 'admin', 'password' => 'UNA_CONTRASEÑA_FUERTE' ],
   'secure_cookies' => true,   // con HTTPS activo (paso 6)
   ```

## 4b. Asistente por IA (opcional)
El chat asistente (relleno por voz/texto, búsqueda de logo e imágenes) se activa
solo si añades sus claves en `api/config.php`, sección `'ai'`. **Las claves nunca
llegan al navegador: solo se usan desde el servidor.** Cada función se enciende
por separado según la clave que pongas:

| Clave | Para qué | Dónde obtenerla |
|-------|----------|-----------------|
| `gemini_api_key` | Agente **y** voz (Gemini transcribe audio) | https://aistudio.google.com/apikey |
| `anthropic_api_key` | Agente (alternativa: Claude) | https://console.anthropic.com |
| `openai_api_key` | Agente y/o voz (Whisper) | https://platform.openai.com |
| `pexels_api_key` | Buscar imágenes de portada | https://www.pexels.com/api/ (gratis) |

- Con **una sola clave de Gemini** ya tienes agente + voz.
- `provider` elige el motor (`'gemini'`, `'anthropic'`, `'openai'` o `'auto'`).
- El logo del cliente se extrae de su web **sin clave**; `logodev_token` es opcional
  para mayor calidad.
- Requiere que la extensión **cURL** de PHP esté activa (lo está por defecto en SiteGround).

## 5. Versión de PHP
En **Site Tools → Devs → PHP Manager** deja PHP en **8.x** (recomendado 8.1+).

## 6. HTTPS
En **Site Tools → Security → SSL Manager** instala el certificado Let's Encrypt
para el subdominio y fuerza HTTPS. Deja `secure_cookies => true` en la config.

## 7. Primer arranque
Abre `https://presupuestos.optimacultura.es`. La primera visita crea el usuario
administrador con la contraseña de la config. Entra y, desde el botón **Usuarios**,
da de alta al resto del equipo.

> Si dejaste `admin.password` vacío, la contraseña se genera y se escribe en
> `data/ADMIN_PASSWORD.txt`. Cópiala y **borra ese archivo**.

## Notas
- **La carpeta de datos va FUERA de `public_html`.** SiteGround sirve los
  ficheros estáticos por NGINX sin pasar por Apache, así que el `.htaccess` no
  protege nada que cuelgue del document root: un `.txt` o una imagen ahí dentro
  son descargables por cualquiera que acierte la URL. Por eso `sqlite_path` en
  `api/config.php` apunta a `.../TU-DOMINIO/private_data/`, un nivel por encima:
  de esa ruta cuelgan las imágenes subidas, la base de datos si usas SQLite y el
  `ADMIN_PASSWORD.txt` del primer arranque.
- Las imágenes (foto/logo) se sirven por PHP solo con sesión iniciada. La carpeta
  se crea automáticamente y debe tener permitido escribir (en SiteGround PHP
  corre como tu usuario, así que funciona sin tocar permisos).
- La base de datos guarda solo el JSON de cada propuesta (con URLs de imagen),
  nunca los binarios; se mantiene ligera.
- Copia de seguridad: exporta la BD MySQL desde Site Tools y respalda
  `data/uploads/`.

## Pruebas en local (sin MySQL)
Con PHP instalado, desde la raíz del proyecto:
```bash
php -S localhost:4000 router.php
```
Usa `api/config.php` con `'driver' => 'sqlite'` (no requiere servidor de BD).
