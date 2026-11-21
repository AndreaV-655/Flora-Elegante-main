# Lineamientos Básicos de Seguridad y Protección de Datos

Este documento establece las medidas mínimas de seguridad que deben aplicarse en el aplicativo para cumplir con los estándares de desarrollo web y los requisitos de evaluación del **Tercer Corte** (Seguridad, Auditoría y Ley 1581 de 2012).

---

## 1. Protección de Rutas y Panel de Administración

- **Ruta de Administración Oculta / No Predecible:**
  - Evita usar rutas obvias como `/admin`, `/administrator` o `/panel`.
  - Define una ruta personalizada mediante variable de entorno (ej. `ADMIN_PATH=/gestion-interna-9x72`) o asegúrate de que, si existe una ruta fija, redirija inmediatamente a un `404 Not Found` (en lugar de `403 Forbidden`) si el usuario no tiene sesión de administrador, para no revelar su existencia.
- **Control de Acceso Basado en Roles (RBAC) en el Backend:**
  - Nunca confíes únicamente en ocultar botones en el frontend (HTML/React/Vue).
  - Todo endpoint del backend debe validar mediante *middlewares* o decoradores:
    1. Que el token de sesión (JWT o cookie segura) sea válido y no haya expirado.
    2. Que el rol del usuario corresponda exactamente al permiso requerido (`ADMIN`, `EMPLEADO`, `CLIENTE`).
- **Protección contra Fuerza Bruta en el Login:**
  - Limita los intentos fallidos de inicio de sesión (ej. máximo 5 intentos por minuto por IP o usuario) para evitar ataques automatizados de adivinado de contraseñas.

---

## 2. Gestión de Secretos y Variables de Entorno

- **Cero Credenciales en el Código Fuente:**
  - Está estrictamente prohibido dejar API Keys (`Cloudflare`, `Pollinations`, `Telegram`), contraseñas de base de datos o claves `SECRET_KEY` quemadas en los archivos del proyecto.
- **Uso Obligatorio de `.env` y `.gitignore`:**
  - Todas las credenciales deben leerse desde variables de entorno.
  - Verifica que `.env` esté incluido en `.gitignore` antes de hacer `git push`.
  - Sube únicamente un archivo `.env.example` con los nombres de las variables vacías o con valores ficticios.
- **Rotación de Claves:**
  - Si por accidente una API Key o token de Telegram se sube a un repositorio público de GitHub, **revócala y genera una nueva inmediatamente** desde la consola del proveedor.

---

## 3. Seguridad en la Subida y Procesamiento de Imágenes (Módulo de Visión)

Dado que el aplicativo recibe fotos y documentos reales de usuarios o empleados, aplica estas reglas:

- **Validación Estricta de Archivos:**
  - Verifica tanto la extensión como el tipo MIME real del archivo en el servidor. Permite únicamente formatos esperados (ej. `image/jpeg`, `image/png`, `image/webp`, `application/pdf`).
  - Limita el tamaño máximo de subida (ej. máximo `5 MB` o `10 MB` por archivo) para evitar ataques de denegación de servicio (DoS) que llenen la memoria o el disco del servidor.
  - Renombra los archivos al guardarlos usando identificadores únicos (UUID) en lugar de usar el nombre original subido por el usuario (previene ataques de *Path Traversal*).
- **Almacenamiento con Control de Acceso:**
  - Las imágenes y documentos procesados **no deben quedar en una carpeta pública accesible sin autenticación** (ej. evitar `/static/uploads/factura1.jpg` abierto a todo internet).
  - Sirve los documentos a través de un endpoint protegido que verifique que el usuario logueado tiene permiso para ver ese archivo.

---

## 4. Protección de Datos Personales (Cumplimiento Ley 1581 de 2012)

La guía exige proteger los datos personales que aparezcan en imágenes o documentos escaneados:

- **Minimización y Anonimización antes de enviar a APIs Externas:**
  - Si el documento contiene datos sensibles (números de cédula, teléfonos, direcciones, datos médicos o bancarios) y vas a usar un modelo en la nube (Cloudflare/Pollinations):
    - **Opción A (Recomendada si usas OCR previo):** Aplica expresiones regulares (Regex) para enmascarar datos sensibles (ej. reemplazar números de documento por `[CEDULA_OCULTA]`) antes de enviar el texto al LLM.
    - **Opción B (Visión directa):** Enmascara o recorta la zona sensible de la imagen antes de enviarla al proveedor; no envíes imágenes sin anonimizar Cloudflare para datos altamente sensibles.
- **Autorización y Finalidad:**
  - Incluye un aviso o casilla de aceptación de tratamiento de datos en el formulario de registro o subida de documentos, indicando claramente para qué se usará la información extraída.
- **No Guardar Imágenes Innecesarias en Logs:**
  - Nunca imprimas en la consola (`print` / `console.log`) el contenido completo en Base64 de las imágenes ni los datos personales completos extraídos por la IA.

---

## 5. Seguridad Web General (API y despliegue con Vercel)

- **HTTPS Obligatorio:** Vercel provee TLS para el frontend; el backend debe publicarse con Caddy y un dominio HTTPS. Usa siempre `https://` en `BACKEND_ORIGIN`, webhooks y llamadas al motor.
- **Configuración Restrictiva de CORS:**
  - En producción, no dejes CORS abierto a todo el mundo (`*`). Configura `ALLOWED_ORIGINS` únicamente con la URL de tu frontend desplegado.
- **Validación de Entradas y SQL Injection:**
  - Usa siempre consultas parametrizadas o un ORM (SQLAlchemy, Prisma, Sequelize, Django ORM, etc.). Nunca concatenes directamente strings del usuario ni respuestas crudas de la IA en consultas SQL.
- **Sanitización de Respuestas de la IA (Prompt Injection):**
  - Trata siempre la salida del modelo de IA como datos no confiables: valida el esquema JSON recibido (con Pydantic, Zod, etc.) antes de ejecutar cualquier automatización o guardar en la base de datos.
- **Registro de Auditoría (Trazabilidad):**
  - Mantén una tabla de auditoría que registre: `usuario_id`, `rol`, `accion_realizada` (ej. *Subida de factura*, *Ejecución de flujo*), `fecha_hora` y `resultado`.