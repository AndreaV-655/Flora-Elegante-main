<?php
// La identidad se toma de la sesión, nunca del JSON enviado por el navegador.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/automation.php';
$u = require_auth();
$action = $_GET['action'] ?? '';
$data = body();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Usa POST.', 405);
if (!in_array($action, ['analyze', 'confirm'], true)) json_error('Acción desconocida.', 404);
if ($action === 'analyze') {
    if (empty($data['consentimiento'])) json_error('Confirma el tratamiento de la imagen.', 400);
    $recent = $_SESSION['vision_ultimo'] ?? 0;
    if (time() - $recent < 10) json_error('Espera unos segundos antes de subir otra foto.', 429);
    $_SESSION['vision_ultimo'] = time();
    if (strlen((string)($data['imagen'] ?? '')) > 7000000) json_error('La imagen supera 5 MB.', 413);
}
try {
    json_out(engine_request('/vision/' . $action, [
        'usuario_id' => (int)$u['id'], 'imagen' => (string)($data['imagen'] ?? ''),
        'id' => (string)($data['id'] ?? ''),
    ]));
} catch (Throwable $e) {
    json_error('No pudimos procesar la imagen. Puedes reintentar o buscar en el catálogo.', 503);
}
