# Flora Elegante

Aplicación web de una florería: catálogo, carrito, pedidos con entrega programada, chatbot, panel administrativo, CRM, reconocimiento de arreglos florales y verificación de facturas de proveedor. Las automatizaciones se implementaron con un outbox transaccional y un worker propio; ya no existe dependencia de n8n.

**URL desplegada:** `PENDIENTE` (sustituir por la URL de Vercel al publicar).

## Arquitectura de despliegue

Vercel publica **solo el frontend estático** y proxya `/api/index.php` hacia un backend HTTPS propio:


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

ados y medidas de seguridad está en `documento_tecnico.md` y `Documento_Tecnico_Final.docx`.
