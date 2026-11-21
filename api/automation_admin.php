<?php
// Observabilidad protegida por rol; no expone cuerpos con datos de clientes.
require_once __DIR__ . '/helpers.php';
require_auth(true);
$db = db();
$action = $_GET['action'] ?? 'list';
if ($action === 'retry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)(body()['id'] ?? 0);
    $db->prepare("UPDATE fe_job SET estado='pendiente', intentos=0, disponible=NOW() WHERE id=? AND estado='fallido'")->execute([$id]);
    $db->prepare('INSERT INTO fe_audit (accion, referencia, resultado) VALUES (?, ?, ?)')->execute(['reintento_manual', (string)$id, 'administrador:' . usuario_actual()['id']]);
    json_out(['ok' => true]);
}
if ($action === 'cost' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = body();
    if (!is_numeric($d['costo'] ?? null) || (float)$d['costo'] < 0) json_error('Costo inválido.', 400);
    $db->prepare('UPDATE producto SET costo=? WHERE id=?')->execute([round((float)$d['costo'], 2), (int)($d['id'] ?? 0)]);
    json_out(['ok' => true]);
}
json_out(['ok' => true,
    'jobs' => $db->query('SELECT id, clave, canal, estado, intentos, creado, resultado FROM fe_job ORDER BY id DESC LIMIT 100')->fetchAll(),
    'audit' => $db->query('SELECT * FROM fe_audit ORDER BY id DESC LIMIT 100')->fetchAll(),
    'logs' => $db->query('SELECT id, nombre_flujo, fecha_inicio, fecha_fin, estado, resultado_o_error
                          FROM logs_automatizacion ORDER BY id DESC LIMIT 100')->fetchAll(),
    'productos' => $db->query('SELECT id, nombre, costo FROM producto ORDER BY nombre')->fetchAll()]);
