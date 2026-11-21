<?php
// ============================================================
// CONEXIÓN PDO A POSTGRESQL / SUPABASE
// ============================================================
require_once __DIR__ . '/config.php';

// Una sola zona horaria para todo: la de TIMEZONE (api/config.php).
date_default_timezone_set(TIMEZONE);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . DB_NAME;
        if (getenv('DB_SSL') === '1') {
            $dsn .= ';sslmode=' . (getenv('DB_SSL_MODE') ?: 'require');
            if (getenv('DB_SSL_CA')) $dsn .= ';sslrootcert=' . getenv('DB_SSL_CA');
        }
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // now() usa la zona de la sesión: sin esto, pedido.fecha y date()
        // pueden diferir horas aunque el valor esté guardado en UTC.
        try {
            $pdo->exec("SET TIME ZONE '" . str_replace("'", "''", TIMEZONE) . "'");
        } catch (Throwable $e) {
            // Sin zona horaria en la BD no se rompe nada, solo queda la
            // diferencia de horas en las fechas.
        }
    }
    return $pdo;
}
