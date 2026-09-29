<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Método no permitido.');
}

$in = body();
$row = userByToken(clean((string) ($in['token'] ?? ''), 64));
if (!$row) {
    respond(401, 'La sesión ya no es válida. Inicia sesión de nuevo.');
}

$bag = jsonList($in['bag'] ?? []);
$licenses = jsonList($in['licenses'] ?? []);
$addons = jsonList($in['addons'] ?? []);
$orders = jsonList($in['orders'] ?? []);
$upd = db()->prepare('UPDATE cuentas SET bolsa = ?, licencias = ?, complementos = ?, pedidos = ? WHERE id = ?');
$upd->execute([$bag, $licenses, $addons, $orders, $row['id']]);

if (($in['event'] ?? '') === 'order') {
    $last = decodeList($orders);
    $order = $last ? $last[count($last) - 1] : null;
    if (is_array($order)) {
        $text = "Pedido de demostración en la tienda IXCHEL\n\n"
            . "Cuenta: {$row['nombre']} <{$row['correo']}>\n"
            . "Unidad: {$row['unidad']}\n"
            . "Pedido: " . clean((string) ($order['id'] ?? ''), 40) . "\n"
            . "Total: " . clean((string) ($order['total'] ?? ''), 20) . "\n"
            . "Pago: " . clean((string) ($order['payL'] ?? ''), 80) . "\n";
        sendMail('Pedido IXCHEL: ' . $row['nombre'], $text);
    }
}

respond(200, '', ['ok' => true]);
