<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Método no permitido.');
}

$in = body();
$email = strtolower(clean((string) ($in['email'] ?? ''), 190));
$password = (string) ($in['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    respond(400, 'Escribe tu correo y tu contraseña.');
}

$st = db()->prepare('SELECT * FROM cuentas WHERE correo = ?');
$st->execute([$email]);
$row = $st->fetch();
if (!$row || !password_verify($password, $row['pass_hash'])) {
    respond(401, $row ? 'La contraseña no es correcta.' : 'No encontramos una cuenta con ese correo. Crea una cuenta.');
}

$token = bin2hex(random_bytes(32));
$upd = db()->prepare('UPDATE cuentas SET token = ? WHERE id = ?');
$upd->execute([$token, $row['id']]);
$row['token'] = $token;
respond(200, '', ['user' => publicUser($row)]);
