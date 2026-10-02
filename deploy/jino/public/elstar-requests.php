<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
$private = dirname(__DIR__, 2) . '/elstar-private';
if (!is_file($private . '/config.php') || !is_file($private . '/requests.php')) {
    respond(['error' => 'Приём заявок пока не подключён. Позвоните: +7 (925) 908-89-88.'], 503);
}
try {
    $config = require $private . '/config.php';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = [$config['origin'] ?? '', 'https://elstar-light.ru'];
    if ($origin === '' || !in_array($origin, $allowedOrigins, true)) {
        respond(['error' => 'Недопустимый источник запроса.'], 403);
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(['error' => 'Используйте форму заявки.'], 405);
    if (!($config['enabled'] ?? false)) respond(['error' => 'Приём заявок пока не подключён. Позвоните: +7 (925) 908-89-88.'], 503);
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') respond(['error' => 'Требуется защищённое соединение.'], 400);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16 * 1024 * 1024) respond(['error' => 'Файлы слишком большие.'], 413);
    require $private . '/requests.php';
    elstarReceive($config, $private);
} catch (Throwable $error) {
    respond(['error' => 'Не удалось сохранить заявку. Попробуйте ещё раз или позвоните: +7 (925) 908-89-88.'], 503);
}
