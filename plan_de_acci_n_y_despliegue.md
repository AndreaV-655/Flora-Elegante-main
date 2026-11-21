# Plan de Acción: Entrega Final - Tercer Corte

Este documento organiza los pasos exactos en el orden solicitado para completar el desarrollo, despliegue y documentación del proyecto de **Desarrollo de Aplicaciones Empresariales**.

---

## Paso 1: Documento Técnico (Primero en Markdown y luego a Word)

Para cumplir con el entregable del **Documento Técnico (máximo 10 páginas)** de forma limpia y estructurada, se debe generar primero en formato `.md` y posteriormente convertirlo a `.docx` (Word) usando herramientas como **Pandoc** o copiando con formato desde un visor Markdown.

### Estructura obligatoria del Documento Técnico (según la guía):
1. **Portada e Introducción:** Nombre del proyecto, integrantes, objetivo del sistema.
2. **Arquitectura Final:** Diagrama de arquitectura (puedes usar Mermaid en el `.md` y exportar la imagen para Word) explicando cómo el backend reemplazó a n8n y cómo se conecta con el módulo de visión.
3. **Inventario de Flujos Migrados:** Tabla comparativa (Disparador, Qué hacía en n8n, Cómo se programó en código propio, Manejo de errores y logs).
4. **Módulo de Visión por Computador:**
   - Caso de uso elegido (ej. lectura de facturas, verificación de documentos, etc.).
   - Flujo de captura (Web/Celular/Chatbot) y extracción en JSON con nivel de confianza.
5. **Resultados de Pruebas:**
   - Evidencia y tabla de resultados de las **20 imágenes reales** tomadas con celular, reportando el **porcentaje de aciertos por campo u objeto**.
   - Resultados de pruebas unitarias de los servicios de automatización.
6. **Seguridad y Protección de Datos (Ley 1581 de 2012):**
   - Roles implementados (Admin, Empleado, Cliente).
   - Identificación de datos personales capturados, finalidad, autorización y medidas de protección.
7. **Limitaciones y Plan B:** Qué ocurre cuando la API gratuita falla o llega a su cuota límite y cómo responde el sistema.

> **Comando rápido para pasar de `.md` a Word (`.docx`) si tienes Pandoc instalado:**
> ```bash
> pandoc documento_tecnico.md -o Documento_Tecnico_Final.docx
> ```

---

## Paso 2: Actualizar el `README.md` del Repositorio

El archivo `README.md` en la raíz de Git debe permitir a cualquier persona (y al docente) levantar el proyecto sin problemas. Asegúrate de incluir:

- **Descripción del Proyecto:** Resumen de qué hace el sistema empresarial y qué caso de uso de visión resuelve.
- **Stack Tecnológico:** Lenguaje/Framework usado, base de datos, librerías de IA (`LangChain`/`LangGraph`), API de visión/LLM, y servicio de despliegue.
- **Requisitos Previos:** Versiones de Python/Node, base de datos, etc.
- **Variables de Entorno (`.env.example`):** Lista de todas las claves necesarias (sin valores reales), incluyendo API Keys de IA, credenciales de BD, Token de Telegram y Chat ID.
- **Instalación y Ejecución Local:** Comandos paso a paso (`git clone`, instalación de dependencias, migraciones de BD, comando de arranque).
- **Credenciales de Prueba y Enlace Desplegado:** URL pública de Vercel y usuarios de prueba (Admin y Empleado) para la sustentación.

---

## Paso 3: Despliegue con Vercel, Supabase y IA en la nube

### A. Arquitectura adoptada
* El frontend se publica en **Vercel** con `scripts/build-vercel.mjs`; Vercel no ejecuta PHP ni Python y solo reenvía `/api/index.php` al backend HTTPS indicado en `BACKEND_ORIGIN`.
* El backend se ejecuta en Docker Compose con Caddy, PHP 8.3, el motor FastAPI y `worker.py`. Las credenciales y la base de datos no se publican en Vercel.
* La base de datos es **Supabase PostgreSQL** (`sql/supabase_postgres.sql`), con sesión en `America/Mexico_City` y TLS obligatorio.
* La IA usa **Pollinations** para texto y **Cloudflare Workers AI** para visión/OCR y respaldo (`AI_PROVIDER=pollinations`, `AI_FALLBACK=cloudflare`). No hay modelos locales que consumir RAM GPU.

### B. Paso a Paso para Desplegar
1. **Preparar el Repositorio:**
   - Mantén actualizados `engine/requirements.txt` y `.env.example`.
   - Verifica que `.env`, `.venv`, `node_modules` y `.vercel` estén en `.gitignore`.
   - Ejecuta `$env:BACKEND_ORIGIN = "https://api.tudominio.com"; node scripts\build-vercel.mjs`.
2. **Base de Datos en la Nube:**
   - En Supabase, crea el proyecto y pega `sql/supabase_postgres.sql` en **SQL Editor**.
   - Copia el host del **Session pooler** (o el host directo si el servidor tiene IPv6), puerto `5432`, usuario `postgres` y contraseña a `DB_*`.
3. **Backend HTTPS:**
   - Levanta `docker compose -f compose.yaml -f compose.public.yaml up --build -d` con `SITE_DOMAIN` definido.
   - Caddy publica 80/443; el motor y el worker permanecen privados.
4. **Cargar Variables de Entorno:**
   - En el servidor, completa `.env` con `DB_*`, `ENGINE_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_API_TOKEN`, `POLLINATIONS_API_KEY`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `SECRET_KEY` y `ADMIN_SECRET_PATH`.
   - En Vercel define únicamente `BACKEND_ORIGIN`.
5. **Verificación:**
   - Comprueba `/api/index.php?resource=auth&action=csrf`, el panel de administración, un pedido de prueba y el envío de automatizaciones.
   - Ejecuta `python -m unittest discover -s tests -v` antes de publicar.

---

## Paso 4: Módulo y Rol de Empleado (Carga de Fotos y Documentos)

Para cumplir con el control de acceso y auditoría de la guía, debes habilitar el flujo exclusivo para el perfil de **Empleado**:

1. **Autenticación y Permisos:**
   - Crea el rol `EMPLEADO` en tu tabla de usuarios (separado de `ADMIN` y `CLIENTE`).
   - Protege las rutas de subida de archivos para que solo usuarios autenticados con rol `EMPLEADO` (o `ADMIN`) puedan acceder.
2. **Interfaz de Captura (Web y Móvil):**
   - Crea una vista dedicada (ej. `/empleado/subir-documento`) con un formulario que permita:
     - Seleccionar archivo desde galería/PC o abrir directamente la cámara del celular (`<input type="file" accept="image/*,.pdf" capture="environment">`).
     - Mostrar una vista previa antes de enviar.
3. **Flujo de Validación Humana (Baja Confianza):**
   - Al subir la foto, el backend la envía al módulo de visión y recibe el JSON estructurado junto con un campo `confianza` (ej. `0.0` a `1.0`).
   - **Regla de la guía:** Si la confianza es baja (ej. `< 0.80`), muestra los datos extraídos en un formulario editable al empleado para que los corrija/confirme **antes** de guardarlos definitivamente en la base de datos.
4. **Auditoría:**
   - Guarda en la base de datos: ruta/URL de la imagen, JSON extraído, `empleado_id` (quién la subió) y `fecha_hora` (cuándo se subió).

---

## Paso 5: Lógica de Automatizaciones y Configuración de Telegram

Aquí debes reemplazar lo que hacía n8n por servicios internos en tu código, garantizando reintentos, manejo de errores (`try/catch`), logs en base de datos y notificaciones a Telegram.

### A. Estructura Lógica de cada Automatización (Servicio en Código)
Cada flujo migrado debe seguir este patrón:
1. **Disparador (Trigger):** Puede ser un evento interno (ej. *Empleado confirma factura válida*), un Webhook o una tarea programada (`APScheduler` / `node-cron`).
2. **Ejecución con Manejo de Errores y Reintentos:**
   - Si la llamada a la IA o servicio externo falla, reintenta máximo 2 o 3 veces con una pausa breve.
   - Si falla definitivamente, activa el **Plan B** (ej. cambia al modelo secundario o marca el registro como `PENDIENTE_REVISION` y notifica el error sin tumbar la aplicación).
3. **Registro de Auditoría (Log de Automatización):**
   - Inserta un registro en una tabla `logs_automatizacion` con: `nombre_flujo`, `fecha_inicio`, `datos_entrada`, `estado` (`EXITO` / `ERROR`), y `resultado_o_error`.

### B. Apartado de Configuración para Telegram
Deja centralizada la configuración de Telegram mediante variables de entorno para que puedas cambiar el destinatario (usuario individual o grupo empresarial) sin tocar el código:

#### 1. Variables necesarias en tu archivo `.env` del backend:
```env
# Token obtenido desde @BotFather en Telegram
TELEGRAM_BOT_TOKEN=tu_token_aqui_123456:ABC-DEF...

# ID del usuario o del grupo que recibirá las alertas/reportes
# Nota: Si es un usuario personal es un número positivo (ej. 1045982312)
# Nota: Si es un grupo o supergrupo empieza con signo menos (ej. -1001982345678)
TELEGRAM_CHAT_ID=-1000000000000
```

#### 2. ¿Qué necesitas tener listo en Telegram?
- **Crear el Bot:** Hablar con `@BotFather` en Telegram -> `/newbot` -> copiar el `HTTP API Token`.
- **Si las notificaciones van a un Grupo:**
  1. Agrega tu bot al grupo de Telegram.
  2. Dale permisos de administrador (o asegúrate de que pueda enviar mensajes).
  3. Envía un mensaje cualquiera en el grupo y consulta `https://api.telegram.org/bot<TU_TOKEN>/getUpdates` en el navegador para copiar el `chat.id` (recuerda incluir el signo `-`).
- **Si el Chatbot también recibe fotos por Telegram (Opcional/Recomendado):**
  - Configura un endpoint `/webhook/telegram` en tu backend (o usa *polling*) que detecte cuando un empleado envíe una foto al bot, descargue la imagen usando la API de Telegram (`getFile`), la pase por tu módulo de visión y responda en el mismo chat con el resultado estructurado.