<?php
// ============================================================
// PEDIDOS
// - POST create      → crear pedido (cliente): valida inventario y
//                      actualiza stock del producto para el que se compró.
//                      Luego genera el recibo con su código único.
// - GET  list        → historial del cliente autenticado.
// - GET  adminList   → todos los pedidos (admin) con detalle y cliente.
// - POST updateEstado→ cambiar estado (admin).
// ============================================================
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/recibo.php';
require_once __DIR__ . '/automation.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$db     = db();

function detalle_pedido(int $id, PDO $db): array {
    $st = $db->prepare("SELECT pd.producto_id, p.nombre, p.nombre_en, p.imagen_url,
                               pd.cantidad, pd.precio_unitario
                        FROM pedido_detalle pd
                        JOIN producto p ON p.id = pd.producto_id
                        WHERE pd.pedido_id = ?");
    $st->execute([$id]);
    return $st->fetchAll();
}

// Valida la fecha/hora de entrega contra el horario del negocio:
// lunes a sábado 8:00-20:00 y domingo 9:00-14:00. Debe ser futura.
// Devuelve el valor normalizado ('Y-m-d H:i:s') o null si no es válida.
function entrega_normalizar(?string $valor): ?string {
    $valor = trim((string)$valor);
    if ($valor === '') return null;

    // Se exige que el formato coincida por completo: getLastErrors evita
    // aceptar valores "parciales" que DateTime rellenaría con datos actuales.
    $dt = null;
    foreach (['Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $fmt) {
        $c  = DateTime::createFromFormat($fmt, $valor);
        $er = DateTime::getLastErrors();
        $limpio = ($er === false) || ($er['warning_count'] === 0 && $er['error_count'] === 0);
        if ($c instanceof DateTime && $limpio) { $dt = $c; break; }
    }
    if (!$dt) return null;

    $dow    = (int)$dt->format('w');           // 0 = domingo
    $abre   = $dow === 0 ? 9 : 8;              // dom 9:00 · lun-sáb 8:00
    $cierra = $dow === 0 ? 14 : 20;            // dom 14:00 · lun-sáb 20:00
    $hora   = (int)$dt->format('G');

    if ($hora < $abre || $hora >= $cierra) return null;
    if ($dt <= new DateTime()) return null;

    return $dt->format('Y-m-d H:i:s');
}

// Tipo de ocasión especial: se normaliza a un identificador corto
// ('cumpleanos', 'aniversario', 'otro') para guardarlo y notificarlo al worker.
function ocasion_normalizar(?string $valor): ?string {
    $v = strtolower(trim((string)$valor));
    if ($v === '') return null;
    $v = strtr($v, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
    $map = [
        'cumpleanos' => 'cumpleanos', 'cumple' => 'cumpleanos', 'birthday' => 'cumpleanos',
        'aniversario' => 'aniversario', 'anniversary' => 'aniversario',
        'otro' => 'otro', 'otra' => 'otro', 'other' => 'otro',
    ];
    if (isset($map[$v])) return $map[$v];
    return mb_substr(trim((string)$valor), 0, 40) ?: null;
}

// Fecha exacta de la ocasión (fecha sola o con hora). Devuelve 'Y-m-d H:i:s'
// o null. Se guarda a medianoche cuando el cliente solo elige el día.
// Nota: se usa el prefijo '!' para que los campos no indicados (hora) NO se
// rellenen con la hora actual, y se exige que el formato coincida del todo.
function fecha_ocasion_normalizar(?string $valor): ?string {
    $valor = trim((string)$valor);
    if ($valor === '') return null;

    foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d'] as $fmt) {
        $dt  = DateTime::createFromFormat('!' . $fmt, $valor);
        $err = DateTime::getLastErrors();
        $limpio = ($err === false) || ($err['warning_count'] === 0 && $err['error_count'] === 0);
        if ($dt instanceof DateTime && $limpio) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return null;
}

// Teléfono: se conserva el formato escrito; se exige entre 7 y 15 dígitos.
// Devuelve '' si viene vacío; null si es inválido (lo decide el llamador).
function telefono_normalizar(?string $valor): ?string {
    $v = trim((string)$valor);
    if ($v === '') return '';
    $digitos = preg_replace('/\D/', '', $v);
    if (strlen($digitos) < 7 || strlen($digitos) > 15) return null;
    return $v;
}

switch ($action) {

    case 'create': {
        $u = require_auth();
        $d  = body();
        $items = $d['items'] ?? [];
        if (!$items || !is_array($items)) json_error('El pedido no tiene productos.', 400);

        // Fecha y hora de entrega: obligatoria y dentro del horario del negocio.
        $entrega = entrega_normalizar($d['entrega'] ?? null);
        if (!$entrega) {
            json_error('Elige una fecha y hora de entrega válidas (lun-sáb 8:00-20:00, dom 9:00-14:00).', 400);
        }

        // ¿Es para una ocasión especial (cumpleaños, aniversario...)?
        // Si sí: se guarda el tipo y la fecha exacta de la ocasión (si no la
        // envían, se usa el día de la entrega). Si no: todo queda NULL.
        $evento      = !empty($d['evento']);
        $ocasion     = $evento ? ocasion_normalizar($d['ocasion'] ?? null) : null;
        $fechaOc     = $evento ? fecha_ocasion_normalizar($d['fechaOcasion'] ?? null) : null;
        $fechaEvento = $evento ? ($fechaOc ?: substr($entrega, 0, 10) . ' 00:00:00') : null;

        // Dedicatoria para la tarjeta (opcional).
        $dedicatoria = mb_substr(trim((string)($d['dedicatoria'] ?? '')), 0, 500);

        // Teléfono de contacto: el del pedido o, si no lo envían, el del perfil.
        $telefono = telefono_normalizar($d['telefono'] ?? null);
        if ($telefono === null) {
            json_error('Ingresa un teléfono válido (entre 7 y 15 dígitos).', 400);
        }
        if ($telefono === '') {
            $stTel = $db->prepare("SELECT telefono FROM cliente_perfil WHERE usuario_id = ?");
            $stTel->execute([$u['id']]);
            $telefono = (string)($stTel->fetchColumn() ?: '');
        }

        // El carrito puede traer líneas repetidas del mismo producto: se
        // agrupan para validar el stock (y descontarlo) sobre el total real.
        $grupos = [];
        foreach ($items as $it) {
            $pid = (int)($it['id'] ?? 0);
            $qty = (int)($it['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) json_error('Producto o cantidad no válido.', 400);
            $grupos[$pid] = ($grupos[$pid] ?? 0) + $qty;
        }

        try {
            $db->beginTransaction();

            // Validar stock de cada producto y calcular el total
            $total = 0.0;
            $productos = [];
            foreach ($grupos as $pid => $qty) {
                $st = $db->prepare("SELECT id, nombre, nombre_en, precio, stock, costo FROM producto WHERE id = ? AND estado = 'activo' FOR UPDATE");
                $st->execute([$pid]);
                $p = $st->fetch();
                if (!$p) {
                    $db->rollBack();
                    json_error('Producto no encontrado. Verifica tu carrito.', 400);
                }
                if ((int)$p['stock'] < $qty) {
                    $db->rollBack();
                    json_error('Stock insuficiente para uno de los productos. Verifica tu carrito.', 400);
                }
                $total += (float)$p['precio'] * $qty;
                $productos[] = ['p' => $p, 'qty' => $qty];
            }

            // Cupón personal, vigente y de un solo uso. El bloqueo impide canje doble.
            $cupon = strtoupper(trim((string)($d['cupon'] ?? '')));
            $descuento = 0.0;
            if ($cupon !== '') {
                $st = $db->prepare('SELECT * FROM fe_cupon WHERE codigo = ? FOR UPDATE');
                $st->execute([$cupon]);
                $c = $st->fetch();
                if (!$c || (int)$c['cliente_id'] !== (int)$u['id'] || $c['usado_pedido'] !== null || $c['vence'] < date('Y-m-d')) {
                    $db->rollBack();
                    json_error('El cupón no es válido, venció o ya fue utilizado.', 400);
                }
                $descuento = round($total * min(100, max(0, (int)$c['porcentaje'])) / 100, 2);
                $total -= $descuento;
            }
            // Crear el pedido
            $db->prepare("INSERT INTO pedido (cliente_id, estado, total, entrega, fecha_evento, dedicatoria, ocasion) VALUES (?, 'Confirmado', ?, ?, ?, ?, ?)")
               ->execute([$u['id'], $total, $entrega, $fechaEvento, $dedicatoria !== '' ? $dedicatoria : null, $ocasion]);
            $pedidoId = (int)$db->lastInsertId();

            // Detalles + actualizar inventario
            $stDet = $db->prepare("INSERT INTO pedido_detalle (pedido_id, producto_id, cantidad, precio_unitario, costo_unitario) VALUES (?, ?, ?, ?, ?)");
            $stUpd = $db->prepare("UPDATE producto SET stock = stock - ? WHERE id = ?");
            foreach ($productos as $it) {
                $stDet->execute([$pedidoId, $it['p']['id'], $it['qty'], $it['p']['precio'], $it['p']['costo']]);
                $stUpd->execute([$it['qty'], $it['p']['id']]);
            }

            // ----- Recibo con código único -----
            // Se genera dentro de la transacción junto con los mensajes pendientes.
            $lineas = [];
            foreach ($productos as $it) {
                $lineas[] = [
                    'productoId'     => (int)$it['p']['id'],
                    'nombre'         => (string)$it['p']['nombre'],
                    'nombreEn'       => (string)$it['p']['nombre_en'],
                    'cantidad'       => (int)$it['qty'],
                    'precioUnitario' => (float)$it['p']['precio'],
                    'subtotal'       => (float)$it['p']['precio'] * (int)$it['qty'],
                ];
            }

            $recibo = recibo_generar($pedidoId, $lineas);

            // Guardar el teléfono en el perfil del cliente (best-effort).
            if ($telefono !== '') {
                try {
                    $db->prepare("INSERT INTO cliente_perfil (usuario_id, telefono) VALUES (?, ?)
                                  ON CONFLICT (usuario_id) DO UPDATE SET telefono = EXCLUDED.telefono")
                       ->execute([$u['id'], $telefono]);
                } catch (Throwable $e) {
                    error_log('[pedido] no se pudo guardar el teléfono del perfil: ' . $e->getMessage());
                }
            }

            $stDireccion = $db->prepare('SELECT direccion FROM cliente_perfil WHERE usuario_id = ?');
            $stDireccion->execute([$u['id']]);
            $direccion = trim((string)($stDireccion->fetchColumn() ?: ''));
            if ($direccion === '') {
                $db->rollBack();
                json_error('Completa tu dirección en Mis datos antes de comprar.', 400);
            }
            $db->prepare('UPDATE pedido SET descuento = ? WHERE id = ?')->execute([$descuento, $pedidoId]);
            if ($cupon !== '') $db->prepare('UPDATE fe_cupon SET usado_pedido = ? WHERE codigo = ?')->execute([$pedidoId, $cupon]);
            // Snapshot: aunque después cambie el perfil, el aviso conserva la dirección de compra.
            $payload = [
                'tipo'            => 'pedido.creado',
                'origen'          => 'flora-elegante',
                'idioma'          => (string)($d['lang'] ?? 'es'),
                'pedido'          => [
                    'id'           => $pedidoId,
                    'codigo'       => $recibo['codigo'],
                    'estado'       => 'Confirmado',
                    'fecha'        => date('Y-m-d H:i:s'),
                    'total'        => $total,
                    'descuento'    => $descuento,
                    'entrega'      => $entrega,
                    'evento'       => $evento,
                    'ocasion'      => $ocasion,
                    'fecha_evento' => $fechaEvento,
                    'dedicatoria'  => $dedicatoria !== '' ? $dedicatoria : null,
                ],
                'cliente'         => [
                    'id'       => (int)$u['id'],
                    'nombre'   => (string)$u['nombre'],
                    'email'    => (string)$u['email'],
                    'telefono' => $telefono !== '' ? $telefono : null,
                    'direccion' => $direccion,
                ],
                'flor_solicitada' => implode(', ', array_map(fn($l) => $l['nombre'], $lineas)),
                'items'           => array_map(fn($l) => [
                    'producto_id'     => $l['productoId'],
                    'nombre'          => $l['nombre'],
                    'nombre_en'       => $l['nombreEn'],
                    'cantidad'        => $l['cantidad'],
                    'precio_unitario' => $l['precioUnitario'],
                    'subtotal'        => $l['subtotal'],
                ], $lineas),
                'recibo'          => $recibo,
            ];
            automation_enqueue($db, 'pedido:' . $pedidoId . ':telegram', 'pedido_telegram', $payload);
            automation_enqueue($db, 'pedido:' . $pedidoId . ':email', 'pedido_email', $payload);
            $db->commit();

            json_out([
                'ok' => true,
                'pedido' => [
                    'id'     => $pedidoId,
                    'total'  => $total,
                    'estado' => 'Confirmado',
                    'codigo' => $recibo['codigo'],
                    'entrega'=> $entrega,
                    'fecha_evento' => $fechaEvento,
                    'ocasion' => $ocasion,
                    'dedicatoria' => $dedicatoria !== '' ? $dedicatoria : null,
                ],
                'recibo' => $recibo,
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Error de BD (u otro): JSON limpio en lugar de un 500 con HTML.
            error_log('[pedido] ' . $e->getMessage());
            json_error('No se pudo crear el pedido. Por favor inténtalo de nuevo.', 500);
        }
    }

    case 'list': {
        $u = require_auth();
        $st = $db->prepare("SELECT id, fecha, estado, total, codigo, entrega, fecha_evento, dedicatoria, ocasion
                            FROM pedido WHERE cliente_id = ? ORDER BY id DESC");
        $st->execute([$u['id']]);
        $pedidos = [];
        foreach ($st->fetchAll() as $ped) {
            $ped['items'] = detalle_pedido((int)$ped['id'], $db);
            $ped['email'] = $u['email'];
            $pedidos[] = $ped;
        }
        json_out(['ok' => true, 'pedidos' => $pedidos]);
    }

    case 'adminList': {
        require_auth(true);
        $st = $db->query("SELECT pe.id, pe.cliente_id, pe.fecha, pe.estado, pe.total,
                                 pe.codigo, pe.entrega, pe.fecha_evento, pe.dedicatoria, pe.ocasion,
                                 u.nombre, u.email
                          FROM pedido pe
                          JOIN usuario u ON u.id = pe.cliente_id
                          ORDER BY pe.id DESC");
        $pedidos = [];
        foreach ($st->fetchAll() as $ped) {
            $ped['items'] = detalle_pedido((int)$ped['id'], $db);
            $pedidos[] = $ped;
        }
        json_out(['ok' => true, 'pedidos' => $pedidos]);
    }

    case 'updateEstado': {
        require_auth(true);
        $d = body();
        $id = (int)($d['id'] ?? 0);
        $estado = trim($d['estado'] ?? '');
        $permitidos = ['Confirmado', 'En camino', 'Entregado', 'Cancelado'];
        if ($id <= 0) json_error('ID de pedido requerido.', 400);
        if (!in_array($estado, $permitidos, true)) json_error('Estado no válido.', 400);

        try {
            $db->beginTransaction();

            // Se bloquea el pedido: el inventario se repara o se vuelve a
            // reservar dentro de la misma transacción.
            $st = $db->prepare("SELECT estado FROM pedido WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $actual = $st->fetchColumn();
            if ($actual === false) {
                $db->rollBack();
                json_error('Pedido no encontrado.', 404);
            }
            $actual = (string)$actual;

            if ($estado !== $actual) {
                if ($estado === 'Cancelado') {
                    // Cancelar: el stock reservado vuelve al inventario.
                    $det = $db->prepare("SELECT producto_id, cantidad FROM pedido_detalle WHERE pedido_id = ?");
                    $det->execute([$id]);
                    $lineas = $det->fetchAll();
                    $upd = $db->prepare("UPDATE producto SET stock = stock + ? WHERE id = ?");
                    foreach ($lineas as $l) {
                        $upd->execute([(int)$l['cantidad'], (int)$l['producto_id']]);
                    }
                } elseif ($actual === 'Cancelado') {
                    // Reactivar un pedido cancelado: el stock se reserva otra
                    // vez (y debe alcanzar).
                    $det = $db->prepare("SELECT pd.producto_id, pd.cantidad, p.stock
                                         FROM pedido_detalle pd
                                         JOIN producto p ON p.id = pd.producto_id
                                         WHERE pd.pedido_id = ? FOR UPDATE");
                    $det->execute([$id]);
                    $lineas = $det->fetchAll();
                    foreach ($lineas as $l) {
                        if ((int)$l['stock'] < (int)$l['cantidad']) {
                            $db->rollBack();
                            json_error('No hay stock suficiente para reactivar este pedido.', 400);
                        }
                    }
                    $upd = $db->prepare("UPDATE producto SET stock = stock - ? WHERE id = ?");
                    foreach ($lineas as $l) {
                        $upd->execute([(int)$l['cantidad'], (int)$l['producto_id']]);
                    }
                }

                $db->prepare("UPDATE pedido SET estado = ? WHERE id = ?")->execute([$estado, $id]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[pedido estado] ' . $e->getMessage());
            json_error('No se pudo actualizar el estado del pedido.', 500);
        }
        json_out(['ok' => true]);
    }

    default:
        json_error('Acción no reconocida.', 404);
}
