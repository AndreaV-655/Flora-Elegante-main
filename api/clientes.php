<?php
// ============================================================
// CLIENTES (CRM del panel admin)
// - GET list → clientes con perfil. Datos SENSIBLES (identificación,
//   método de pago) quedan excluidos; solo se expone edad derivada.
// ============================================================
require_once __DIR__ . '/helpers.php';
require_auth(true);
$db = db();

$st = $db->query("SELECT u.nombre, u.email, u.fecha_registro,
                         cp.telefono, cp.direccion, cp.fecha_nacimiento,
                         cp.preferencias_florales
                  FROM usuario u
                  LEFT JOIN cliente_perfil cp ON cp.usuario_id = u.id
                  WHERE u.rol_id = (SELECT id FROM rol WHERE nombre = 'cliente')
                  ORDER BY u.fecha_registro DESC");

$clientes = [];
foreach ($st->fetchAll() as $c) {
    // La fecha de nacimiento (PRIVADO) nunca sale: la edad se calcula
    // aquí en el servidor y es lo único que viaja al panel.
    $edad = null;
    if (!empty($c['fecha_nacimiento'])) {
        try {
            $nac = new DateTime((string)$c['fecha_nacimiento']);
            $edad = (int)$nac->diff(new DateTime('today'))->y;
            if ($edad < 0 || $edad > 130) $edad = null;
        } catch (Throwable $e) {
            $edad = null;
        }
    }
    $clientes[] = [
        'nombre'        => $c['nombre'],
        'email'         => $c['email'],
        'telefono'      => $c['telefono'],
        'direccion'     => $c['direccion'],
        'edad'          => $edad,
        'preferencias'  => $c['preferencias_florales'],
        // identificacion y metodo_pago: NO se incluyen (SENSIBLES)
    ];
}

json_out(['ok' => true, 'clientes' => $clientes]);
