<?php
require_once __DIR__ . '/helpers.php';
$u = require_auth();
$db = db();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $enabled = !empty(body()['marketing']) ? 1 : 0;
        $db->beginTransaction();
        $db->prepare('INSERT INTO cliente_perfil (usuario_id,marketing) VALUES (?,?) ON CONFLICT (usuario_id) DO UPDATE SET marketing=EXCLUDED.marketing')->execute([$u['id'], $enabled]);
        if (!$enabled) {
            $db->prepare("UPDATE fe_job SET estado='cancelado' WHERE canal='cupon_email' AND estado='pendiente' AND clave LIKE ?")->execute(['cupon:' . $u['id'] . ':%']);
        }
        $db->prepare('INSERT INTO fe_audit (accion,referencia,resultado) VALUES (?,?,?)')->execute(['consentimiento_marketing', (string)$u['id'], $enabled ? 'aceptado' : 'revocado']);
        $db->commit();
    }
    $st = $db->prepare('SELECT marketing FROM cliente_perfil WHERE usuario_id=?');
    $st->execute([$u['id']]);
    json_out(['ok' => true, 'marketing' => (bool)$st->fetchColumn()]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    json_error('No se pudo guardar la preferencia. Verifica que las migraciones estén aplicadas.', 503);
}
