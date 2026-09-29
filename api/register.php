<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Método no permitido.');
}

$in = body();
$name = clean((string) ($in['name'] ?? ''), 160);
$email = strtolower(clean((string) ($in['email'] ?? ''), 190));
$password = (string) ($in['password'] ?? '');
$cedula = clean((string) ($in['cedula'] ?? ''), 40);
$unit = clean((string) ($in['unit'] ?? ''), 190);
$bag = is_array($in['bag'] ?? null) ? $in['bag'] : [];

if (count(preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY)) < 2) {
    respond(400, 'Escribe tu nombre completo.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, 'Escribe un correo válido.');
}
if (strlen($password) < 8 || !preg_match('/\d/', $password)) {
    respond(400, 'La contraseña necesita 8 caracteres y al menos un número.');
}
if (mb_strlen_safe($unit) < 2) {
    respond(400, 'Escribe tu unidad o consultorio.');
}

$exists = db()->prepare('SELECT id FROM cuentas WHERE correo = ?');
$exists->execute([$email]);
if ($exists->fetch()) {
    respond(409, 'Ya existe una cuenta con este correo. Inicia sesión.');
}

$token = bin2hex(random_bytes(32));
$insert = db()->prepare('INSERT INTO cuentas (nombre, correo, pass_hash, cedula, unidad, token, bolsa, licencias, complementos, pedidos, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, "[]", "[]", "[]", ?)');
$insert->execute([
    $name,
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    $cedula,
    $unit,
    $token,
    jsonList($bag),
    date('c'),
]);

$text = "Nueva cuenta en la tienda IXCHEL\n\n"
    . "Nombre: {$name}\n"
    . "Correo: {$email}\n"
    . "Cédula: " . ($cedula !== '' ? $cedula : '(no indicó)') . "\n"
    . "Unidad o consultorio: {$unit}\n"
    . "Fecha: " . date('Y-m-d H:i') . "\n\n"
    . "Bolsa al crear la cuenta:\n" . bagLines($bag);
$mailed = sendMail('Nueva cuenta IXCHEL: ' . $name, $text);

$row = db()->prepare('SELECT * FROM cuentas WHERE correo = ?');
$row->execute([$email]);
respond(200, '', ['user' => publicUser($row->fetch()), 'mailed' => $mailed]);

function mb_strlen_safe(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}
