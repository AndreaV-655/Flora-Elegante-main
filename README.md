# Flora Elegante

Aplicación web de una florería: catálogo, carrito, pedidos con entrega programada, chatbot, panel administrativo, CRM, reconocimiento de arreglos florales y verificación de facturas de proveedor. Las automatizaciones se implementaron con un outbox transaccional y un worker propio; ya no existe dependencia de n8n.

**URL desplegada:** `PENDIENTE` (sustituir por la URL de Vercel al publicar).

## Arquitectura de despliegue

Vercel publica **solo el frontend estático** y proxya `/api/index.php` hacia un backend HTTPS propio:

```text
Navegador
  → Vercel (HTML/JS/CSS + proxy /api/index.php)
      → servidor con PHP 8.3 + PostgreSQL/Supabase + motor Python (Docker Compose)
          → Pollinations (chat) + Cloudflare Workers AI (visión)
          → Cloudflare Workers AI + Pollinations (OCR de facturas)
          → SMTP / Telegram (automatizaciones)
```

`scripts/build-vercel.mjs` construye el directorio de salida con una lista positiva de archivos públicos. No copia `api/`, `engine/`, `sql/`, `.env` ni documentación. Las claves y la base de datos viven únicamente en el backend; Vercel solo recibe `BACKEND_ORIGIN`.

## Stack

- **Frontend:** HTML, Tailwind CSS local, JavaScript sin framework (módulos IIFE).
- **Backend web:** PHP 8.3, PDO/PostgreSQL, sesión `fe_session`, CSRF por token.
- **Base de datos:** PostgreSQL 16 en Supabase con esquema idempotente `sql/supabase_postgres.sql`.
- **Motor de IA:** Python 3.12, FastAPI, LangGraph, Pillow y PyMuPDF.
- **Chat/arreglos:** Pollinations genera las respuestas de texto; Cloudflare Workers AI analiza las fotos y sirve de respaldo.
- **Facturas:** Cloudflare Workers AI transcribe; Pollinations estructura el texto; el servidor valida y la persona confirma.
- **Automatizaciones:** `fe_job` + `worker.py`, con reintentos y `logs_automatizacion`.
- **Despliegue:** Vercel (frontend) + Docker Compose/Caddy (backend).

## Requisitos previos

- PHP 8.3 con `pdo_pgsql`, `mbstring` y `curl` (XAMPP 8.2+ sirve si habilitas `pdo_pgsql`).
- Proyecto de Supabase con PostgreSQL 16 y acceso al Connection pooler.
- Python 3.12/3.13 con `pip` (3.11 también funciona si instala los requisitos).
- Node.js 18+ para el build de Vercel y la validación de JavaScript.
- Docker Desktop + Docker Compose para el backend en servidor.
- Cuentas/credenciales de los proveedores de IA que se vayan a usar.
- Pandoc, solo si se quiere regenerar `Documento_Tecnico_Final.docx`.

## Estructura

```
Flora-Elegante-main/
├── index.html, login.html, cliente.html, admin.html, empleado.html
├── api/                    Controladores PHP y front controller
├── js/                     Módulos del navegador
├── engine/
│   ├── app.py              FastAPI privada (Bearer), chat, visión y documentos
│   ├── core.py             Reglas puras y validación de salidas de IA
│   ├── document_ocr.py     Contratos con Cloudflare y Pollinations
│   ├── worker.py           Envíos, reintentos y logs
│   ├── storage.py          PostgreSQL, auditoría y outbox
│   ├── evaluate_vision.py  Evaluación con 20+ fotos reales
│   └── evaluate_documentos.py  Precisión por campo con 20+ facturas reales
├── sql/                    Tres migraciones en orden
├── tests/                  38 pruebas sin credenciales reales
├── scripts/build-vercel.mjs  Build Output API de Vercel
├── vercel.json, .vercelignore
├── compose.yaml            PHP + motor + worker contra Supabase
├── compose.public.yaml, Caddyfile  HTTPS con dominio propio
└── Dockerfile.web, engine/Dockerfile, requirements.txt
```

## Variables de entorno

Copia `.env.example` a `.env` y complétalo. Nunca subas `.env` ni pegues claves en `vercel.json`, HTML o JavaScript.

| Variable | Uso |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Supabase: host del pooler, `5432`, `postgres` y credenciales de BD |
| `DB_SSL`, `DB_SSL_MODE`, `DB_SSL_CA` | TLS hacia Supabase; usar `DB_SSL=1` y `DB_SSL_MODE=require` |
| `TIMEZONE` | `America/Mexico_City` para PHP y Python |
| `ENGINE_URL`, `ENGINE_TOKEN` | Puente privado PHP → FastAPI |
| `TRUSTED_PROXIES` | IPs o CIDR del proxy inverso autorizado a enviar `X-Forwarded-Proto` |
| `AI_PROVIDER`, `AI_FALLBACK` | `pollinations` y `cloudflare`; cadena de respaldo |
| `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_API_TOKEN` | Visión, OCR y respaldo de texto |
| `POLLINATIONS_API_KEY`, `POLLINATIONS_TEXT_MODEL` | Chat, estructuración del OCR y respaldo |
| `VISION_STORAGE` | Carpeta privada de imágenes, montada como volumen |
| `SMTP_*`, `TELEGRAM_*` | Entrega de automatizaciones |
| `STOCK_THRESHOLD`, `REMINDER_DAYS`, `COUPON_PERCENT` | Reglas de negocio |
| `DEMO_MODE`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Solo demostración / creación inicial |
| `SITE_DOMAIN` | Dominio del backend detrás de Caddy |
| `BACKEND_ORIGIN` | **Solo en Vercel**: origen HTTPS del backend PHP |

La clave `sb_publishable_*` identifica el proyecto en el frontend de Supabase; **no** reemplaza al host, usuario ni contraseña de la base de datos. Para este proyecto, `SUPABASE_URL=https://wixgvbpydrfaglpbdufv.supabase.co` y el host directo es `db.wixgvbpydrfaglpbdufv.supabase.co` (puerto `5432`, usuario `postgres`). Si el servidor no tiene IPv6, usa **Project Settings → Database → Connection string → Session pooler** de tu región y usuario `postgres.wixgvbpydrfaglpbdufv`; el código ajusta la zona horaria de la sesión, por eso no se usa el *Transaction pooler*.

## Instalación local contra Supabase

1. Clona el repositorio dentro de `htdocs` (o working directory local).
2. En Supabase abre **SQL Editor**, pega todo `sql/supabase_postgres.sql` y ejecútalo. El script crea las 16 tablas, índices, rol `empleado` y datos base; es idempotente.
3. Crea el entorno Python e instala dependencias:
   ```powershell
   python -m venv .venv
   .venv\Scripts\pip install -r engine\requirements.txt
   ```
4. Define variables en la terminal que arranca cada proceso (un `.env` no las carga automáticamente con uvicorn):
   ```powershell
   $env:ENGINE_TOKEN = (python -c "import secrets; print(secrets.token_hex(32))")
   $env:ENGINE_URL = "http://127.0.0.1:8000"
   $env:AI_PROVIDER = "pollinations"
   $env:AI_FALLBACK = "cloudflare"
   $env:CLOUDFLARE_ACCOUNT_ID = "TU_ACCOUNT_ID"
   $env:CLOUDFLARE_API_TOKEN = "TU_TOKEN"
   $env:POLLINATIONS_API_KEY = "TU_CLAVE"
   $env:DB_HOST = "db.wixgvbpydrfaglpbdufv.supabase.co"
   $env:DB_PORT = "5432"
   $env:DB_SSL = "1"
   $env:DB_USER = "postgres"
   $env:DB_PASS = "CAMBIAR_PASSWORD_BASE_DE_DATOS"
   ```
 5. En dos terminales, arranca la API y el worker:
    ```powershell
    .venv\Scripts\python -m uvicorn app:app --host 127.0.0.1 --port 8000 --app-dir engine
    .venv\Scripts\python engine\worker.py
    ```
 6. Abre `http://localhost/Flora-Elegante-main/index.html`. Para usar las cuentas demo, define `DEMO_MODE=1` antes de iniciar Apache.

## Backend con Docker Compose

```bash
cp .env.example .env
# Edita .env: contraseñas, ENGINE_TOKEN, BACKEND_ORIGIN, proveedores de IA, correo y Telegram.
docker compose up --build -d
```

- La base de datos ya no se levanta en Compose: PHP y Python se conectan al pooler de Supabase con `DB_*`.
- Para HTTPS con dominio propio, ejecuta también `docker compose -f compose.yaml -f compose.public.yaml up -d` con `SITE_DOMAIN` definido. El proxy Caddy publica el puerto 80/443; el motor y el worker no exponen puertos públicos.
- Configura `TRUSTED_PROXIES` con la IP o red desde la que Caddy conecta con PHP si quieres que `X-Forwarded-Proto: https` se acepte.
- Supabase exige TLS: deja `DB_SSL=1` y `DB_SSL_MODE=require`.

## Despliegue en Vercel

1. En tu servidor (VPS u otro hosting con Docker), despliega `compose.yaml` + `compose.public.yaml` con un dominio HTTPS, por ejemplo `https://api.tudominio.com`. Ejecuta `sql/supabase_postgres.sql` en el SQL Editor de Supabase y crea el administrador real con `ADMIN_EMAIL` / `ADMIN_PASSWORD`; deja `DEMO_MODE=0`.
2. Verifica que `https://api.tudominio.com/api/index.php?resource=auth&action=csrf` responda JSON y que el puerto 8000 del motor **no** sea público.
3. Importa el repositorio en Vercel. Como es un proyecto sin framework, `vercel.json` usa `node scripts/build-vercel.mjs`.
4. En **Project Settings → Environment Variables** define solo:
   ```dotenv
   BACKEND_ORIGIN=https://api.tudominio.com
   ```
   Sin barra final, sin ruta, sin credenciales y con HTTPS.
5. Despliega. Vercel servirá los HTML/JS/CSS y proxyará `/api/index.php` al backend; cualquier otra ruta `/api/*` devuelve 404.
6. Sustituye `PENDIENTE` en este README y en la portada del documento técnico por la URL real de Vercel.

**Límites:** Vercel no ejecuta PHP, Python ni consulta Supabase directamente; el backend debe estar siempre disponible. El almacenamiento de imágenes se mantiene en el volumen del motor, no en Vercel. Si el backend se reinicia, los metadatos siguen en Supabase; conserva el volumen `vision` para no perder los archivos.

## Cuentas demo (solo local)

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | `admin@floraelegante.mx` | `Admin2025*` |
| Empleado | `empleado@floraelegante.mx` | `Empleado2025*` |
| Cliente | `cliente@demo.mx` | `Cliente123*` |

Se crean únicamente con `DEMO_MODE=1`. **No actives el modo demo en producción** y cambia estas contraseñas antes de publicar.

## Roles y módulos

- **Cliente:** catálogo, carrito, entrega, cupones, historial, perfil, consentimiento de marketing y chatbot.
- **Empleado:** `empleado.html`; sube foto o PDF de una factura, revisa los datos propuestos y confirma. La confianza menor a 80 % se resalta. El OCR y la estructura se obtienen de Cloudflare y Pollinations; la aceptación explícita del empleado es obligatoria.
- **Administrador:** panel de negocio, productos, pedidos, reportes, CRM sin datos sensibles, automatizaciones, costos y logs.

## Visión por computador

- **Arreglos florales:** el modelo propone un `producto_id`; `core.vision_result` descarta IDs inventados y exige confirmación.
- **Facturas:** JPG, PNG, WebP o PDF de una página (máximo 5 MB). Se elimina EXIF/GPS, se rasteriza el PDF, Cloudflare transcribe el texto y Pollinations propone JSON. El OCR se persiste antes de la estructuración: si Pollinations falla, el documento queda en captura manual. Ningún dato se guarda como confirmado sin revisión humana.

**Importante:** quitar EXIF no anonimiza el contenido visible. El empleado debe ocultar identificaciones, teléfonos, direcciones, correos y firmas antes de subir. La casilla de anonimización registra esa declaración; no existe detección automática de datos personales. La transcripción se envía a Pollinations, por lo que la revisión de ese texto es parte del procedimiento.

Para medir precisión con documentos reales:

```powershell
.venv\Scripts\python engine\evaluate_vision.py ruta\manifiesto_arreglos.csv tests\results\vision_reporte.json
.venv\Scripts\python engine\evaluate_documentos.py ruta\manifiesto_facturas.csv tests\results\documentos_reporte.json
```

Los CSV deben apuntar a archivos reales. Las métricas siguen **pendientes**; no se reportan porcentajes inventados.

## Automatizaciones

El pedido y sus mensajes se escriben en la misma transacción (`fe_job`). El worker envía por SMTP/Telegram, reintenta con espera exponencial (hasta 5 intentos) y registra cada ejecución en `logs_automatizacion`.

| Flujo | Disparador | Salida |
|---|---|---|
| Recibo del pedido | Compra confirmada | Correo al cliente |
| Aviso interno de pedido | Compra confirmada | Telegram con entrega y dedicatoria |
| Stock bajo | Revisión periódica | Telegram al cruzar el umbral |
| Cupón de aniversario | Fecha de compra + 7 días | Correo con cupón de un uso |
| Cierre mensual | Día 1 | Telegram con ventas y utilidad bruta |
| Confirmación de visión | Stock bajo tras confirmación | Telegram |
| Revocación de marketing | Cliente desactiva consentimiento | Cancela cupones pendientes |

### Conectar Telegram

`https://t.me/FloraEleganteBot` es un bot de **Telegram**, no de Instagram. El enlace público solo permite abrirlo; por sí solo no entrega credenciales. Para conectarlo:

1. El dueño del bot abre @BotFather en Telegram, ejecuta `/mybots`, elige `FloraEleganteBot` y entra a **API Token**. Si nadie guardó el token, ejecuta `/token` para generar uno nuevo; esto invalida el anterior.
2. Envía `/start` a @FloraEleganteBot desde la cuenta o grupo que recibirá los avisos. Luego consulta `getUpdates` con el token y copia `message.chat.id` como `TELEGRAM_CHAT_ID` (positivo para usuario, negativo para grupo; agrega antes al bot al grupo).
3. Completa `TELEGRAM_BOT_TOKEN` y `TELEGRAM_CHAT_ID` en `.env` y ejecuta:

```powershell
python scripts\check_telegram.py
```

El script valida el token con `getMe`, verifica que pertenezca a `@FloraEleganteBot` y envía un mensaje de prueba con `sendMessage`. No imprime el token; si faltan credenciales o Telegram las rechaza, termina con error. Las automatizaciones usan esos mismos valores en `engine/worker.py`.

## Seguridad y datos personales

- Contraseñas con `password_hash()`; consultas preparadas y validación de tipos/longitudes.
- CSRF en todo POST; cookie `HttpOnly`, `SameSite=Lax` y `Secure` cuando hay TLS.
- `X-Forwarded-Proto` solo se acepta si el peer está en `TRUSTED_PROXIES`.
- Controladores `api/*.php` bloqueados frente a acceso directo; solo se ejecutan desde `api/index.php`.
- Autorización por rol en backend y defensa adicional en el motor; los documentos pertenecen al usuario de la sesión.
- Límite de 8 intentos fallidos de login por correo+IP en 15 minutos y bitácora de intentos.
- Datos sensibles (`identificacion`, `metodo_pago`) fuera del CRM y de respuestas públicas.
- Imágenes en almacenamiento privado, sin EXIF/GPS; archivos y logs sin tokens ni cuerpos HTTP.
- Auditoría en `fe_audit` y `logs_automatizacion`. Encabezados `nosniff`, `X-Frame-Options` y `Referrer-Policy` en Apache y Caddy.
- Alineación con la Ley 1581 de 2012 y, para operación en México, con la LFPDPPP: finalidad, consentimiento, minimización y derechos ARCO.
- Limitación declarada: no hay cifrado a nivel de columna; se recomienda cifrado de disco/base, rotación de credenciales y política de retención.

## Pruebas

```powershell
# PHP
Get-ChildItem api\*.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }

# JavaScript
Get-ChildItem js\*.js | ForEach-Object { node --check $_.FullName }

# Python (38 pruebas)
python -m venv .venv
.venv\Scripts\pip install -r engine\requirements.txt
.venv\Scripts\python -m unittest discover -s tests -v
```

Resultado esperado: 38/38 pruebas correctas y sin errores de sintaxis PHP/JS. Si el `.venv` existente apunta a una instalación de Python retirada, recrea el entorno antes de ejecutar.

## Pendientes

- Publicar el frontend en Vercel y el backend con HTTPS; reemplazar la URL `PENDIENTE`.
- Ejecutar las evaluaciones con 20 fotos de arreglos y 20 facturas reales anonimizadas.
- Completar nombres, códigos, curso y docente en la portada de `Documento_Tecnico_Final.docx`.
- Rotar cualquier credencial que haya estado en un archivo local o historial.

El detalle de arquitectura, flujos migrados, resultados y medidas de seguridad está en `documento_tecnico.md` y `Documento_Tecnico_Final.docx`.
