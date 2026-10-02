<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function localReply(array $value, int $status = 200): never { http_response_code($status); echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit; }
require __DIR__ . '/elstar-local-runtime.php';
try {
    $route = $_GET['route'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($route === 'catalog' && $method === 'GET') localReply(['sources' => localSources()]);
    if ($route === 'file' && $method === 'GET') {
        $supplier = $_GET['supplier'] ?? ''; $revision = $_GET['revision'] ?? ''; $file = $_GET['file'] ?? '';
        if (!is_string($supplier) || !in_array($supplier, ['isonex', 'lightstar'], true) || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision) || !is_string($file) || !preg_match('/^(catalog|details-\d{1,3})\.json$/D', $file)) localReply(['error' => 'Not found'], 404);
        $path = localCatalog() . '/' . $supplier . '/' . $revision . '/' . $file;
        if (!is_file($path)) localReply(['error' => 'Not found'], 404);
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($path); exit;
    }
    if ($route !== 'ticket' || $method !== 'POST') localReply(['error' => 'Not found'], 404);
    $origin = localOrigin();
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $origin) localReply(['error' => 'Недопустимый источник запроса.'], 403);
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) localReply(['error' => 'Проверьте заявку.'], 400);
    $body = file_get_contents('php://input', false, null, 0, 16001);
    if ($body === false || strlen($body) > 16000) localReply(['error' => 'Слишком большой запрос.'], 413);
    $raw = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($raw) || array_is_list($raw)) localReply(['error' => 'Проверьте заявку.'], 400);
    $config = require localPrivate() . '/config.php';
    if (!($config['enabled'] ?? false)) localReply(['error' => 'Приём заявок пока не подключён.'], 503);
    $inventory = ($raw['kind'] ?? '') === 'order' ? localInventory() : [];
    localReply(localSigned(localSelection($raw, $inventory, $origin, time())));
} catch (LocalPriceChanged $error) {
    localReply(['code' => 'PRICE_CHANGED', 'error' => $error->getMessage(), 'items' => $error->items, 'total' => $error->total], 409);
} catch (InvalidArgumentException | JsonException $error) {
    localReply(['error' => $error instanceof JsonException ? 'Проверьте заявку.' : $error->getMessage()], 400);
} catch (Throwable $error) {
    localReply(['error' => 'Не удалось проверить каталог и корзину. Позвоните: +7 (925) 908-89-88.'], 503);
}
