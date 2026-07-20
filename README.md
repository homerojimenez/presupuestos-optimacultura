# Constructor de propuestas ÓptimaCultura

Aplicación para construir propuestas comerciales (audioguías, ticketing y VR),
con previsualización, exportación a PDF e histórico compartido en base de datos.

## Cómo ejecutar

Requisitos: **Node.js ≥ 22.5** (trae SQLite integrado). **Sin dependencias que instalar.**

```bash
cd server
node server.js
```

Luego abre **http://localhost:4000**.

En el **primer arranque** se crea un usuario `admin` y se muestra su contraseña
por consola (o defínela tú con `PRESUPUESTOS_ADMIN_PASSWORD`). Los datos —base de
datos SQLite e imágenes subidas— se guardan en `data/` (no versionado).

### Variables de entorno

| Variable | Por defecto | Descripción |
|----------|-------------|-------------|
| `PORT` | `4000` | Puerto del servidor |
| `PRESUPUESTOS_ADMIN_USER` | `admin` | Usuario admin inicial |
| `PRESUPUESTOS_ADMIN_PASSWORD` | (aleatoria) | Contraseña admin inicial |
| `PRESUPUESTOS_SECURE_COOKIES` | `0` | Pon `1` al servir tras HTTPS (cookies `Secure`) |

## Arquitectura

- **Frontend:** `index.html` (una sola página, JS vanilla, sin build).
- **Backend:** `server/` — Node nativo (`node:http`, `node:sqlite`, `node:crypto`),
  cero dependencias npm. Sirve el frontend y una API REST.
- **Auth:** login por sesión (cookies httpOnly); contraseñas con scrypt+salt.
- **Datos:** propuestas en SQLite; imágenes como ficheros en `data/uploads/`
  (protegidas por sesión). Histórico compartido con búsqueda por nombre y servicios.

## Historial de versiones

**v5.0.0** — Backend real con base de datos:
- Guardado de propuestas en histórico compartido (SQLite).
- Buscador por nombre de cliente y por servicios incluidos.
- Login real multiusuario con roles (admin/usuario) y gestión de usuarios.
- Imágenes almacenadas como ficheros referenciados (no base64 en la BD).

**v4.4.6** — Exportación VR usando canvas final en impresión para asegurar que el
logo quede incluido en el PDF.

**v4.4.0**
- Eliminada la opción de multiplicar el logo.
- Añadido botón de "Eliminar fondo" para el logo.
- El logo procesado se usa tanto en portada como en el mockup VR.
- El algoritmo intenta eliminar fondos de color sólido tomando el color del borde
  del archivo y retirándolo por continuidad.
