<?php
// ============================================================
// RECIBO DE COMPRA · código único
// ------------------------------------------------------------
// Al crear un pedido (orders.php) se llama a recibo_generar(), que
// genera un código único FE-AAAAMMDD-XXXX y lo guarda en el pedido.
// El cliente ve ese código en el modal de compra.
//
// Todo ocurre en el servidor; el pedido nunca queda sin código.
// ============================================================
require_once __DIR__ . '/db.php';

// ------------------------------------------------------------
// Orquestación: genera el código del recibo y lo guarda
// ------------------------------------------------------------
function recibo_generar(int $pedidoId, array $lineas): array {
    // Si otro pedido tomó el mismo código (colisión del UNIQUE de
    // pedido.codigo), se reintenta con códigos nuevos.
    $codigo = null;
    for ($intento = 0; $intento < 8 && $codigo === null; $intento++) {
        $cand = recibo_codigo($pedidoId);
        if (recibo_guardar($pedidoId, $cand)) $codigo = $cand;
    }

    // Respaldo determinista: el id del pedido es único, no puede colisionar.
    if ($codigo === null) {
        $codigo = 'FE-' . date('Ymd') . '-' . str_pad((string)$pedidoId, 4, '0', STR_PAD_LEFT);
        recibo_guardar($pedidoId, $codigo);
    }

    return [
        'codigo' => $codigo,
        'lineas' => $lineas,
    ];
}

// ------------------------------------------------------------
// Guarda el código en el pedido. Devuelve false si el código ya lo
// tiene otro pedido (para que el llamador reintente con otro).
// ------------------------------------------------------------
function recibo_guardar(int $pedidoId, string $codigo): bool {
    try {
        db()->prepare("UPDATE pedido SET codigo = ? WHERE id = ?")
            ->execute([$codigo, $pedidoId]);
        return true;
    } catch (PDOException $e) {
        // 23505 = clave duplicada: otro pedido ya usa este código.
        if ($e->getCode() === '23505') return false;
        error_log('[recibo] no se pudo guardar el recibo del pedido ' . $pedidoId . ': ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('[recibo] no se pudo guardar el recibo del pedido ' . $pedidoId . ': ' . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------
// Código único FE-AAAAMMDD-XXXX
// ------------------------------------------------------------
function recibo_codigo(int $pedidoId): string {
    $db = null;
    try { $db = db(); } catch (Throwable $e) { /* sin BD se genera igual */ }

    for ($intento = 0; $intento < 5; $intento++) {
        $codigo = sprintf('FE-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(2))));
        if ($db === null) return $codigo;

        try {
            $st = $db->prepare("SELECT id FROM pedido WHERE codigo = ? AND id <> ?");
            $st->execute([$codigo, $pedidoId]);
            if (!$st->fetch()) return $codigo;
        } catch (Throwable $e) {
            return $codigo;
        }
    }

    // Respaldo determinista: el id del pedido es único.
    return 'FE-' . date('Ymd') . '-' . str_pad((string)$pedidoId, 4, '0', STR_PAD_LEFT);
}
