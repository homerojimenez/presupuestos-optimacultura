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
- **Permisos:** un administrador ve y gestiona el histórico de todo el equipo;
  un usuario normal solo las propuestas que ha creado él. El filtro se aplica en
  el servidor (listado, apertura, edición y borrado), no solo en la interfaz.
- **Datos:** propuestas en SQLite; imágenes como ficheros en `data/uploads/`
  (protegidas por sesión). Histórico con búsqueda por nombre y servicios, acotado
  al alcance de cada rol.

## Asistente por IA (opcional)

Un chat flotante ("Asistente", abajo a la derecha) permite **rellenar la propuesta
dictando o escribiendo** en lenguaje natural. Es agéntico: además de completar
campos (cliente, servicios, precio, duración, descripciones…), puede **buscar la
imagen de portada** en bancos con licencia comercial y **el logo del cliente** desde
su web, mostrándolos como candidatos para que elijas. También puedes **adjuntar tus
propias fotos** (📎) y colocarlas como portada o logo, y **dictar por voz** (🎤).

Todo el "cerebro" corre en el servidor (`api/ai.php`): las claves de IA se guardan
en `api/config.php` (sección `'ai'`) y **nunca se envían al navegador**. Cada función
se activa cuando su clave está presente; si falta, el chat lo avisa con claridad.
Soporta **Gemini, Claude (Anthropic) u OpenAI** como motor. Detalle de claves en
[`DEPLOY.md`](DEPLOY.md) (paso 4b).

Las descargas de imágenes externas pasan por una guarda anti-SSRF (solo IPs
públicas, con límite de tamaño y verificación de tipo).

## Personalización de la estación VR

La página de realidad virtual se compone en cliente sobre la 15B (la misma página
sin estación) a partir de la **foto calibrada** de la estación (`assets/pages/estacion-vr.webp`):

- **Color del mueble** — paleta de seis colores más color libre, en el bloque
  "Personalización de la estación" (aparece al marcar el servicio OptimaVR). El color
  cae a la vez sobre las tres caras blancas (frontal, tapa y lateral): los polígonos
  de las caras lo recortan y la **máscara del blanco** (`estacion-vr-mask.png`) hace
  que solo toque el metal, dejando intactos madera, pantalla, soporte y cables. La
  mezcla es `multiply`, así respeta sombras y reflejos.
- **Logo del cliente** — se estampa en el frontal con una **homografía real**: el
  escorzo es exacto en todo el plano, no una aproximación por triángulos.
- Sin logo y en color original se usa la página estándar (15A).

Las coordenadas de las caras están medidas sobre esa misma foto y son las mismas que
usa la landing de estaciones VR.

## Historial de versiones

**v5.3.0** — Permisos del histórico: los administradores ven las propuestas de
todo el equipo y los usuarios solo las suyas. Información adicional independiente
para la opción A y la B. Proyección económica: índice generado a partir de los
títulos de las páginas finales y botón para añadir página.

**v5.2.0** — Personalización de la estación VR: color del mueble y logo del cliente
en el frontal con perspectiva real (homografía), sobre la foto calibrada de la estación.

**v5.1.0** — Asistente por IA: chat con relleno por voz/texto, búsqueda de logo
e imágenes de portada, y adjuntar fotos. Motor configurable (Gemini/Claude/OpenAI),
claves solo en servidor.

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
