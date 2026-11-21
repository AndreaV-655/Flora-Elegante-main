<?php
// ============================================================
// INTENTOS DE INICIO DE SESIÓN
// ------------------------------------------------------------
// Cada intento (éxito o fallo) queda registrado en la tabla
// `intento_login` como bitácora de seguridad.
//
// Todo es defensivo: si la tabla no existe, el login sigue
// funcionando con normalidad.
// ============================================================
require_once __DIR__ . '/helpers.php';

// ------------------------------------------------------------
// Registro de intentos
// ------------------------------------------------------------
function login_registrar(string $email, bool $exito): void {
    try {
        db()->prepare("INSERT INTO intento_login (email, ip, exito, user_agent) VALUES (?, ?, ?, ?)")
            ->execute([
                mb_substr($email !== '' ? $email : '(vacío)', 0, 150),
                login_ip(),
                $exito ? 1 : 0,
                login_agente(),
            ]);
    } catch (Throwable $e) {
        // Sin tabla intento_login: no se registra y el login no falla.
    }
}

// ------------------------------------------------------------
// Límite de intentos: 8 fallos por correo+IP en 15 minutos.
// Si la tabla no existe, nunca bloquea el acceso.
// ------------------------------------------------------------
function login_bloqueado(string $email): bool {
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM intento_login
                             WHERE exito = 0 AND email = ? AND ip = ?
                                AND fecha >= now() - INTERVAL '15 minutes'");
        $st->execute([$email, login_ip()]);
        return (int)$st->fetchColumn() >= 8;
    } catch (Throwable $e) {
        return false;
    }
}

// ------------------------------------------------------------
// Datos de la petición (solo lo que cabe en la tabla)
// ------------------------------------------------------------
function login_ip(): string {
    // Detrás de un proxy declarado, el primer X-Forwarded-For es el cliente;
    // en acceso directo se usa REMOTE_ADDR para no aceptar IP falsificada.
    if (proxy_confiable() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) return mb_substr($candidate, 0, 45);
        }
    }
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function login_agente(): string {
    // El navegador envía el agente en HTTP_USER_AGENT (la clave USER_AGENT
    // no existe en $_SERVER).
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}