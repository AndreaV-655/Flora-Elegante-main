<?php
// Módulo de empleado: verificación de comprobantes/facturas con visión.
// La identidad y el rol se toman de la sesión, nunca del JSON del navegador.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/automation.php';

$u = require_auth();
if (!in_array($u['rol'], ['empleado', 'administrador'], true)) {
    json_error('Acceso restringido al personal autorizado.', 403);
}

$action = $_GET['action'] ?? 'list';
$data = body();

if ($action === 'list') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Usa GET.', 405);
    $db = db();
    $sql = "SELECT d.id, d.creado, d.tipo, d.estado, d.confianza, d.resultado,
                   d.datos_confirmados, d.confirmado_en, u.nombre AS empleado
            FROM fe_documento d JOIN usuario u ON u.id = d.empleado_id";
    if ($u['rol'] === 'administrador') {
        $st = $db->query($sql . ' ORDER BY d.creado DESC LIMIT 50');
        $filas = $st->fetchAll();
    } else {
        $st = $db->prepare($sql . ' WHERE d.empleado_id = ? ORDER BY d.creado DESC LIMIT 50');
        $st->execute([$u['id']]);
        $filas = $st->fetchAll();
    }
    foreach ($filas as &$fila) {
        $fila['resultado'] = $fila['resultado'] ? json_decode($fila['resultado'], true) : null;
        $fila['datos_confirmados'] = $fila['datos_confirmados'] ? json_decode($fila['datos_confirmados'], true) : null;
    }
    unset($fila);
    json_out(['ok' => true, 'documentos' => $filas]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Usa POST.', 405);
if (!in_array($action, ['analyze', 'confirm'], true)) json_error('Acción desconocida.', 404);

if ($action === 'analyze') {
    if (empty($data['consentimiento'])) {
        json_error('Confirma el tratamiento del documento antes de subirlo.', 400);
    }
    if (empty($data['anonimizado'])) {
        json_error('Oculta los datos personales del documento antes de enviarlo a los proveedores de IA.', 400);
    }
    $reciente = $_SESSION['documento_ultimo'] ?? 0;
    if (time() - $reciente < 10) json_error('Espera unos segundos antes de subir otro documento.', 429);
    $_SESSION['documento_ultimo'] = time();
    $imagen = (string)($data['imagen'] ?? '');
    if ($imagen === '') json_error('Adjunta una imagen o PDF.', 400);
    if (strlen($imagen) > 7000000) json_error('El archivo supera 5 MB.', 413);
}

try {
    $payload = [
        'usuario_id' => (int)$u['id'],
        'id' => (string)($data['id'] ?? ''),
    ];
    if ($action === 'analyze') {
        $payload['imagen'] = (string)$data['imagen'];
        $payload['consentimiento'] = true;
        $payload['anonimizado'] = true;
    } else {
        if (!isset($data['datos']) || !is_array($data['datos'])) {
            json_error('Envía los datos revisados de la factura.', 400);
        }
        $payload['datos'] = $data['datos'];
    }
    json_out(engine_request('/documento/' . $action, $payload));
} catch (Throwable $e) {
    $msg = $action === 'analyze'
        ? 'No pudimos interpretar el documento. Reintenta o captura los datos manualmente.'
        : 'No pudimos guardar la confirmación. Reintenta.';
    json_error($msg, 503);
}
