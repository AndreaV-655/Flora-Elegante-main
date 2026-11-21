<?php
// ============================================================
// GESTIÓN DE PRODUCTOS (CRUD)
// - GET  list      → catálogo (público, solo activos)
// - GET  adminList → todos incl. inactivos (admin)
// - POST create    → crear (admin)
// - POST update    → editar (admin)
// - POST delete    → eliminar (admin)
// ============================================================
require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$db     = db();

switch ($action) {

    case 'list': {
        $st = $db->query("SELECT p.id, p.categoria_id, c.nombre AS categoria, c.nombre_en AS categoria_en,
                                 p.nombre, p.nombre_en, p.descripcion, p.descripcion_en,
                                 p.precio, p.imagen_url, p.stock, p.estado
                          FROM producto p
                          JOIN categoria c ON c.id = p.categoria_id
                          WHERE p.estado = 'activo'
                          ORDER BY p.id");
        json_out(['ok' => true, 'productos' => $st->fetchAll()]);
    }

    case 'adminList': {
        require_auth(true);
        $st = $db->query("SELECT p.id, p.categoria_id, c.nombre AS categoria, c.nombre_en AS categoria_en,
                                 p.nombre, p.nombre_en, p.descripcion, p.descripcion_en,
                                 p.precio, p.imagen_url, p.stock, p.estado
                          FROM producto p
                          JOIN categoria c ON c.id = p.categoria_id
                          ORDER BY p.id");
        json_out(['ok' => true, 'productos' => $st->fetchAll()]);
    }

    case 'create': {
        require_auth(true);
        $d = body();
        $nombre = trim($d['nombre'] ?? '');
        if (!$nombre) json_error('El nombre es obligatorio.', 400);
        if (mb_strlen($nombre) > 120) json_error('El nombre no puede superar 120 caracteres.', 400);

        $precio = $d['precio'] ?? null;
        if (!is_numeric($precio) || (float)$precio < 0) json_error('El precio debe ser un número mayor o igual a 0.', 400);

        $stock = $d['stock'] ?? 0;
        if (!is_numeric($stock) || (int)$stock < 0) json_error('El stock no puede ser negativo.', 400);

        // La categoría debe existir (FK de la BD, pero se avisa claro).
        $catId = (int)($d['categoria_id'] ?? 0);
        $st = $db->prepare("SELECT id FROM categoria WHERE id = ?");
        $st->execute([$catId]);
        if (!$st->fetch()) json_error('La categoría indicada no existe.', 400);

        // producto.nombre es único: se avisa antes de que la BD rechace.
        $st = $db->prepare("SELECT id FROM producto WHERE nombre = ?");
        $st->execute([$nombre]);
        if ($st->fetch()) json_error('Ya existe un producto con ese nombre.', 409);

        $db->prepare("INSERT INTO producto (categoria_id, nombre, nombre_en, descripcion, descripcion_en, precio, imagen_url, stock, estado)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([
               $catId,
               $nombre,
               mb_substr(trim($d['nombre_en'] ?? ''), 0, 120),
               $d['descripcion'] ?? '',
               $d['descripcion_en'] ?? '',
               (float)$precio,
               $d['imagen_url'] ?? '',
               (int)$stock,
               ($d['estado'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo',
           ]);
        json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    case 'update': {
        require_auth(true);
        $d = body();
        $id = (int)($d['id'] ?? 0);
        if (!$id) json_error('ID de producto requerido.', 400);
        $nombre = trim($d['nombre'] ?? '');
        if (!$nombre) json_error('El nombre es obligatorio.', 400);
        if (mb_strlen($nombre) > 120) json_error('El nombre no puede superar 120 caracteres.', 400);

        $precio = $d['precio'] ?? null;
        if (!is_numeric($precio) || (float)$precio < 0) json_error('El precio debe ser un número mayor o igual a 0.', 400);

        $stock = $d['stock'] ?? 0;
        if (!is_numeric($stock) || (int)$stock < 0) json_error('El stock no puede ser negativo.', 400);

        $catId = (int)($d['categoria_id'] ?? 0);
        $st = $db->prepare("SELECT id FROM categoria WHERE id = ?");
        $st->execute([$catId]);
        if (!$st->fetch()) json_error('La categoría indicada no existe.', 400);

        $st = $db->prepare("SELECT id FROM producto WHERE nombre = ? AND id <> ?");
        $st->execute([$nombre, $id]);
        if ($st->fetch()) json_error('Ya existe otro producto con ese nombre.', 409);

        $db->prepare("UPDATE producto SET categoria_id = ?, nombre = ?, nombre_en = ?, descripcion = ?,
                       descripcion_en = ?, precio = ?, imagen_url = ?, stock = ?, estado = ? WHERE id = ?")
           ->execute([
               $catId,
               $nombre,
               mb_substr(trim($d['nombre_en'] ?? ''), 0, 120),
               $d['descripcion'] ?? '',
               $d['descripcion_en'] ?? '',
               (float)$precio,
               $d['imagen_url'] ?? '',
               (int)$stock,
               ($d['estado'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo',
               $id,
           ]);
        json_out(['ok' => true]);
    }

    case 'update_stock': {
        require_auth(true);
        $d = body();
        $id = (int)($d['id'] ?? 0);
        $stock = $d['stock'] ?? 0;
        if (!is_numeric($stock) || (int)$stock < 0) json_error('El stock no puede ser negativo.', 400);

        $st = $db->prepare("SELECT id FROM producto WHERE id = ?");
        $st->execute([$id]);
        if (!$st->fetch()) json_error('Producto no encontrado.', 404);

        $db->prepare("UPDATE producto SET stock = ? WHERE id = ?")->execute([(int)$stock, $id]);
        json_out(['ok' => true]);
    }

    case 'delete': {
        require_auth(true);
        $d = body();
        $id = (int)($d['id'] ?? 0);

        // Un producto con pedidos no se puede borrar (FK de pedido_detalle):
        // se ofrece desactivarlo, que preserva el historial.
        $st = $db->prepare("SELECT COUNT(*) FROM pedido_detalle WHERE producto_id = ?");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            json_error('Este producto tiene pedidos asociados y no se puede eliminar. Desactívalo en su lugar.', 409);
        }

        $db->prepare("DELETE FROM producto WHERE id = ?")->execute([$id]);
        json_out(['ok' => true]);
    }

    default:
        json_error('Acción no reconocida.', 404);
}
