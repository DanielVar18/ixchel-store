<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function cmsDb(): PDO
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS editores (
        usuario TEXT PRIMARY KEY,
        nombre TEXT NOT NULL,
        salt TEXT NOT NULL,
        pass_hash TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS contenidos (
        clave TEXT PRIMARY KEY,
        tipo TEXT NOT NULL,
        valor TEXT NOT NULL,
        actualizado_en TEXT NOT NULL,
        editor TEXT NOT NULL
    )');
    seedEditors($pdo);
    return $pdo;
}

function seedEditors(PDO $pdo): void
{
    $n = (int) $pdo->query('SELECT COUNT(*) FROM editores')->fetchColumn();
    if ($n > 0) {
        return;
    }
    $rows = [
        ['cora', 'CORA', 'a73599fca6b190c286734d54b7050447', '63f05db33a9cfad523a8b2ebe4737835ebe4fdb424bf916d37066dcad9db3706'],
        ['medico', 'Médico', 'fad740ccfb4cfb965a4ec141b2bbb46a', '23b6ac9c27fd30b859acac22a73d8c6560ebad2170b575d8c6002c4b7a4b69fa'],
    ];
    $st = $pdo->prepare('INSERT INTO editores (usuario, nombre, salt, pass_hash) VALUES (?, ?, ?, ?)');
    foreach ($rows as $row) {
        $st->execute($row);
    }
}

function editorPasswordOk(array $row, string $password): bool
{
    return hash_equals($row['pass_hash'], hash('sha256', $row['salt'] . $password));
}

function requireEditor(): array
{
    $user = $_SESSION['editor'] ?? null;
    $csrf = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_array($user) || $csrf === '' || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
        respond(401, 'Tu sesión de editor terminó. Vuelve a entrar.');
    }
    return $user;
}

function validKey(string $key): bool
{
    return (bool) preg_match('/^[a-z0-9-]{1,80}$/', $key);
}

function contentMaps(PDO $pdo): array
{
    $texts = [];
    $images = [];
    foreach ($pdo->query('SELECT clave, tipo, valor FROM contenidos') as $row) {
        if ($row['tipo'] === 'image') {
            $images[$row['clave']] = $row['valor'];
        } else {
            $texts[$row['clave']] = $row['valor'];
        }
    }
    return ['texts' => $texts, 'images' => $images];
}

function cleanCmsText(string $value): string
{
    $value = trim(str_replace("\0", '', $value));
    $value = strip_tags($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 4000);
    }
    return substr($value, 0, 4000);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    respond(200, '', contentMaps(cmsDb()));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Método no permitido.');
}

$action = $_POST['action'] ?? '';
$in = $action === '' ? body() : [];
if ($action === '') {
    $action = (string) ($in['action'] ?? '');
}

if ($action === 'login') {
    $fails = $_SESSION['editor_fails'] ?? ['n' => 0, 'until' => 0];
    if (($fails['until'] ?? 0) > time()) {
        respond(429, 'Demasiados intentos. Espera unos minutos.');
    }
    $usuario = strtolower(clean((string) ($in['usuario'] ?? ''), 40));
    $password = (string) ($in['password'] ?? '');
    $st = cmsDb()->prepare('SELECT * FROM editores WHERE usuario = ?');
    $st->execute([$usuario]);
    $row = $st->fetch();
    if (!$row || !editorPasswordOk($row, $password)) {
        $fails['n'] = ($fails['n'] ?? 0) + 1;
        if ($fails['n'] >= 6) {
            $fails = ['n' => 0, 'until' => time() + 900];
        }
        $_SESSION['editor_fails'] = $fails;
        respond(401, 'Usuario o contraseña incorrectos.');
    }
    session_regenerate_id(true);
    $_SESSION['editor'] = ['usuario' => $row['usuario'], 'nombre' => $row['nombre']];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $_SESSION['editor_fails'] = ['n' => 0, 'until' => 0];
    respond(200, '', ['editor' => $_SESSION['editor'], 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    respond(200, '');
}

if ($action === 'me') {
    $user = $_SESSION['editor'] ?? null;
    if (!is_array($user)) {
        respond(401, 'Tu sesión de editor terminó. Vuelve a entrar.');
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    respond(200, '', ['editor' => $user, 'csrf' => $_SESSION['csrf']]);
}

$editor = requireEditor();

if ($action === 'password') {
    $actual = (string) ($in['actual'] ?? '');
    $nueva = (string) ($in['nueva'] ?? '');
    if (strlen($nueva) < 8) {
        respond(400, 'La contraseña nueva necesita al menos 8 caracteres.');
    }
    $st = cmsDb()->prepare('SELECT * FROM editores WHERE usuario = ?');
    $st->execute([$editor['usuario']]);
    $row = $st->fetch();
    if (!$row || !editorPasswordOk($row, $actual)) {
        respond(400, 'La contraseña actual no es correcta.');
    }
    $salt = bin2hex(random_bytes(16));
    $upd = cmsDb()->prepare('UPDATE editores SET salt = ?, pass_hash = ? WHERE usuario = ?');
    $upd->execute([$salt, hash('sha256', $salt . $nueva), $editor['usuario']]);
    respond(200, '');
}

if ($action === 'save') {
    $texts = is_array($in['texts'] ?? null) ? $in['texts'] : [];
    $reset = is_array($in['reset'] ?? null) ? $in['reset'] : [];
    if (count($texts) + count($reset) > 300) {
        respond(400, 'Hay demasiados cambios en un solo guardado.');
    }
    $pdo = cmsDb();
    $pdo->beginTransaction();
    $up = $pdo->prepare("INSERT INTO contenidos (clave, tipo, valor, actualizado_en, editor) VALUES (?, 'text', ?, ?, ?)
        ON CONFLICT(clave) DO UPDATE SET tipo = 'text', valor = excluded.valor, actualizado_en = excluded.actualizado_en, editor = excluded.editor");
    $del = $pdo->prepare('DELETE FROM contenidos WHERE clave = ?');
    $now = date('c');
    foreach ($texts as $key => $value) {
        $key = (string) $key;
        if (!validKey($key) || !is_string($value)) {
            continue;
        }
        $up->execute([$key, cleanCmsText($value), $now, $editor['usuario']]);
    }
    foreach ($reset as $key) {
        $key = (string) $key;
        if (validKey($key)) {
            $del->execute([$key]);
        }
    }
    $pdo->commit();
    respond(200, '', contentMaps($pdo));
}

if ($action === 'upload') {
    $key = clean((string) ($_POST['key'] ?? ''), 80);
    if (!validKey($key) || !isset($_FILES['file'])) {
        respond(400, 'No se recibió la imagen.');
    }
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(400, 'No se pudo subir la imagen.');
    }
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        respond(400, 'La imagen debe pesar menos de 4 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        respond(400, 'Usa una imagen JPG, PNG, WEBP o GIF.');
    }
    $dir = dirname(__DIR__) . '/assets/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        respond(500, 'No se pudo guardar la imagen en el servidor.');
    }
    $name = $key . '-' . bin2hex(random_bytes(4)) . '.' . $types[$info[2]];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        respond(500, 'No se pudo guardar la imagen.');
    }
    $url = 'assets/uploads/' . $name;
    $up = cmsDb()->prepare("INSERT INTO contenidos (clave, tipo, valor, actualizado_en, editor) VALUES (?, 'image', ?, ?, ?)
        ON CONFLICT(clave) DO UPDATE SET tipo = 'image', valor = excluded.valor, actualizado_en = excluded.actualizado_en, editor = excluded.editor");
    $up->execute([$key, $url, date('c'), $editor['usuario']]);
    respond(200, '', ['key' => $key, 'url' => $url]);
}

respond(400, 'Acción no reconocida.');
