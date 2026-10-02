<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(array $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit; }
require __DIR__ . '/elstar-local-runtime.php';
try {
    $origin = localOrigin();
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $origin) respond(['error' => 'Недопустимый источник запроса.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(['error' => 'Используйте форму заявки.'], 405);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16 * 1024 * 1024) respond(['error' => 'Файлы слишком большие.'], 413);
    $private = localPrivate();
    $config = require $private . '/config.php';
    if (!($config['enabled'] ?? false)) respond(['error' => 'Приём заявок пока не подключён.'], 503);
    $config['origin'] = $origin;
    $config['ticket_public_key'] = localKey()['public'];
    require $private . '/requests.php';
    elstarReceive($config, $private);
} catch (Throwable $error) {
    respond(['error' => 'Не удалось сохранить заявку. Позвоните: +7 (925) 908-89-88.'], 503);
}
