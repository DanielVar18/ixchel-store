<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function config(): array
{
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        respond(500, 'Falta la configuración del correo.');
    }
    $c = require $path;
    return is_array($c) ? $c : [];
}

function respond(int $code, string $error = '', array $extra = []): void
{
    http_response_code($code);
    echo json_encode(
        array_merge(['ok' => $code < 400, 'error' => $error], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        respond(500, 'No se pudo crear la carpeta de la base de datos.');
    }
    try {
        $pdo = new PDO('sqlite:' . $dir . '/ixchel.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cuentas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            correo TEXT NOT NULL UNIQUE,
            pass_hash TEXT NOT NULL,
            cedula TEXT NOT NULL DEFAULT "",
            unidad TEXT NOT NULL,
            token TEXT,
            bolsa TEXT NOT NULL DEFAULT "[]",
            licencias TEXT NOT NULL DEFAULT "[]",
            complementos TEXT NOT NULL DEFAULT "[]",
            pedidos TEXT NOT NULL DEFAULT "[]",
            creado_en TEXT NOT NULL
        )');
    } catch (Throwable $e) {
        respond(500, 'No se pudo abrir la base de datos.');
    }
    return $pdo;
}

function body(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($data) ? $data : [];
}

function clean(string $value, int $max): string
{
    $value = trim(str_replace(["\r", "\n"], ' ', $value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function jsonList($value): string
{
    if (!is_array($value)) {
        return '[]';
    }
    return json_encode(array_values($value), JSON_UNESCAPED_UNICODE) ?: '[]';
}

function decodeList(?string $value): array
{
    $data = json_decode($value ?: '[]', true);
    return is_array($data) ? $data : [];
}

function publicUser(array $row): array
{
    return [
        'name' => $row['nombre'],
        'email' => $row['correo'],
        'ced' => $row['cedula'],
        'unit' => $row['unidad'],
        'token' => $row['token'],
        'bag' => decodeList($row['bolsa']),
        'licenses' => decodeList($row['licencias']),
        'addons' => decodeList($row['complementos']),
        'orders' => decodeList($row['pedidos']),
    ];
}

function userByToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM cuentas WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

function bagLines(array $bag): string
{
    if (!$bag) {
        return "(vacía)\n";
    }
    $lines = '';
    foreach ($bag as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['type'] ?? '') === 'plan') {
            $periodo = (($item['cyc'] ?? '') === 'y') ? 'anual' : 'mensual';
            $lines .= '- ' . clean((string) ($item['go'] ?? ''), 40) . ' · plan básico ' . $periodo . ' · ' . (int) ($item['q'] ?? 1) . " licencia(s)\n";
        } else {
            $lines .= '- complemento ' . clean((string) ($item['k'] ?? ''), 40) . ' de ' . clean((string) ($item['go'] ?? ''), 40) . ' · ' . (int) ($item['months'] ?? 0) . " meses\n";
        }
    }
    return $lines !== '' ? $lines : "(vacía)\n";
}

function sendMail(string $subject, string $text): bool
{
    $c = config();
    $to = $c['mail_to'] ?? 'gerencia@ix-chel.com.mx';
    $from = $c['mail_from'] ?? 'gerencia@ix-chel.com.mx';
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "From: IXCHEL Store <{$from}>\r\n";
    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $encoded, $text, $headers);
}
