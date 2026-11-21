<?php
// ============================================================
// CONFIGURACIÓN DE LA BASE DE DATOS (PostgreSQL / Supabase)
// ------------------------------------------------------------
// Los valores se toman de variables de entorno; en Supabase usa el
// pooler y la contraseña de la base de datos, nunca la publishable key.
// ============================================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ============================================================
// ZONA HORARIA DEL NEGOCIO
// ------------------------------------------------------------
// PHP y PostgreSQL deben usar la misma zona o las fechas no cuadran
// (por ejemplo, la hora del pedido en la BD contra la del recibo).
// El negocio opera en Ciudad de México. Cámbiala si operas en otro
// país; la sesión de PostgreSQL se ajusta con este mismo valor.
// ============================================================
define('TIMEZONE', getenv('TIMEZONE') ?: 'America/Mexico_City');

// La IA (Cloudflare/Pollinations) vive en el motor Python y se configura con
// variables de entorno del servicio (ver .env.example). PHP nunca
// recibe ni guarda la clave del proveedor.
