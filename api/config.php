<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

return [
    'mail_to' => 'gerencia@ix-chel.com.mx',
    'mail_from' => 'gerencia@ix-chel.com.mx',
];
