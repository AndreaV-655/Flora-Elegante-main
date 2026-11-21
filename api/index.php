<?php
// ============================================================
// FRONT CONTROLLER · api/index.php?resource=xxx&action=yyy
// ------------------------------------------------------------
// Recibe peticiones JSON en /api/index.php y las delega a los
// controladores según el recurso solicitado.
//
// Ejemplos:
//   GET  api/index.php?resource=products&action=list
//   POST api/index.php?resource=auth&action=login
// ============================================================

declare(strict_types=1);
error_reporting(E_ALL);
// No mostrar errores inline para no corromper las respuestas JSON.
// Los errores sí se registran en el log de PHP de XAMPP.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/helpers.php';

// Los archivos del frontend requieren que la sesión viaje por cookie.
// helpers::session_boot() centraliza HttpOnly, SameSite, Secure y Strict Mode;
// X-Forwarded-Proto solo se acepta detrás de un proxy declarado como confiable.
session_boot();

// Token CSRF por sesión: lo pide el frontend (GET auth/csrf) y debe viajar
// en la cabecera X-CSRF-Token en TODA petición que modifica datos (POST).
if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Para servir el frontend desde el mismo origen (XAMPP) no se requiere
// CORS; se deja preparado por si se accede desde otro puerto/origen.
// El frontend Vercel accede por su proxy del mismo origen; no necesita CORS.
header('Cache-Control: private, no-store');
header('Vercel-CDN-Cache-Control: no-store');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Entrega del token CSRF sin tocar la base de datos.
$resource = $_GET['resource'] ?? '';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
// Los controladores históricos aceptaban mutaciones por GET, eludiendo CSRF.
// Rechazarlas ANTES de abrir la BD; POST seguirá exigiendo el token de sesión.
$writes = [
    'auth' => ['login', 'register', 'logout', 'update_perfil', 'update_password'],
    'products' => ['create', 'update', 'update_stock', 'delete'],
    'orders' => ['create', 'updateEstado'], 'interactions' => ['save'],
    'automation' => ['retry', 'cost'], 'vision' => ['analyze', 'confirm'],
    'empleado' => ['analyze', 'confirm'],
];
if (in_array($action, $writes[$resource] ?? [], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Esta acción requiere POST.']);
    exit;
}
if ($resource === 'auth' && ($_GET['action'] ?? '') === 'csrf') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'token' => $_SESSION['csrf']], JSON_UNESCAPED_UNICODE);
    exit;
}

// Protección CSRF central: todo POST exige el token de la sesión.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Petición no válida (token CSRF ausente o incorrecto). Recarga la página.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Mapa de recursos → archivo controlador
$rutas = [
    'auth'         => 'auth.php',
    'products'     => 'products.php',
    'categories'   => 'categories.php',
    'orders'       => 'orders.php',
    'dashboard'    => 'dashboard.php',
    'clientes'     => 'clientes.php',
    'interactions' => 'interactions.php',
    'chat'         => 'chat.php',
    'vision'       => 'vision.php',
    'empleado'     => 'empleado.php',
    'automation'   => 'automation_admin.php',
    'preferences'  => 'preferences.php',
];

if (!isset($rutas[$resource])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Recurso no encontrado.']);
    exit;
}

// Ejecución del controlador: cualquier excepción no capturada se
// convierte en un 500 con JSON limpio (nunca HTML de error de PHP).
try {
    require_once __DIR__ . '/' . $rutas[$resource];
} catch (Throwable $e) {
    error_log('[api] ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
