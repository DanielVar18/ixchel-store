<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Método no permitido.');
}

$row = userByToken(clean((string) (body()['token'] ?? ''), 64));
if (!$row) {
    respond(401, 'La sesión ya no es válida. Inicia sesión de nuevo.');
}
respond(200, '', ['user' => publicUser($row)]);
