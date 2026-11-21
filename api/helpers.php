<?php
// ============================================================
// FUNCIONES AUXILIARES: JSON, sesión y autorización
// ============================================================
require_once __DIR__ . '/db.php';

// Responde JSON y termina
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_out(['ok' => false, 'msg' => $msg], $code);
}

// Lee el cuerpo JSON de la petición
function body(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $_POST;
}

// Confía en X-Forwarded-Proto solo si el peer está en TRUSTED_PROXIES.
function ip_en_red(string $ip, string $red): bool {
    if (!str_contains($red, '/')) return $ip === $red;
    [$base, $bits] = explode('/', $red, 2);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
        filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
    $mask = (int)$bits === 0 ? 0 : (0xFFFFFFFF << (32 - (int)$bits)) & 0xFFFFFFFF;
    return (ip2long($ip) & $mask) === (ip2long($base) & $mask);
}

function proxy_confiable(): bool {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $redes = explode(',', (string)getenv('TRUSTED_PROXIES'));
    foreach ($redes as $red) {
        if (ip_en_red($peer, trim($red))) return true;
    }
    return false;
}

function peticion_https(): bool {
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https !== '' && $https !== 'off') return true;
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https' && proxy_confiable();
}

// Único punto de arranque de sesión: misma cookie en el front controller y
// en cualquier controlador invocado directamente.
function session_boot(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_name('fe_session');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => peticion_https(),
    ]);
    session_start();
}

// Lee el usuario autenticado desde la sesión PHP
function usuario_actual(): ?array {
    session_boot();
    return $_SESSION['usuario'] ?? null;
}

// Requiere estar autenticado; si no, responde 401
function require_auth(bool $admin = false): array {
    $u = usuario_actual();
    if (!$u) json_error('No autorizado. Inicia sesión.', 401);
    if ($admin && $u['rol'] !== 'administrador') json_error('Acceso restringido a administradores.', 403);
    return $u;
}
