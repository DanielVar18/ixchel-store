<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Método no permitido.');
}

$row = userByToken(clean((string) (body()['token'] ?? ''), 64));
if ($row) {
    $upd = db()->prepare('UPDATE cuentas SET token = NULL WHERE id = ?');
    $upd->execute([$row['id']]);
}
respond(200, '');
