<?php
declare(strict_types=1);
// This file contains no credentials. Runtime keys live outside domains/.
function localPrivate(): string { return dirname(__DIR__, 2) . '/elstar-private'; }
function localCatalog(): string { return __DIR__ . '/catalog-data'; }
function localJson(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('File unavailable');
    $value = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Invalid JSON file');
    return $value;
}
function localOrigin(): string {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if (!in_array($host, ['elstar-light.ru', '3d2ef918ae94.hosting.myjino.ru'], true)) throw new RuntimeException('Unknown host');
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new RuntimeException('HTTPS required');
    return 'https://' . $host;
}
function localKey(): array {
    $path = localPrivate() . '/elstar-local-key.pem';
    $lock = fopen(localPrivate() . '/elstar-local-key.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Key lock unavailable');
    try {
        if (!is_file($path)) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            if (!$key || !openssl_pkey_export($key, $pem)) throw new RuntimeException('Key generation failed');
            $file = fopen($path, 'x');
            if (!$file) throw new RuntimeException('Key unavailable');
            chmod($path, 0600);
            $written = fwrite($file, $pem); fclose($file);
            if ($written !== strlen($pem)) { unlink($path); throw new RuntimeException('Key write failed'); }
        }
        $pem = file_get_contents($path);
        $key = $pem !== false ? openssl_pkey_get_private($pem) : false;
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || empty($details['key'])) throw new RuntimeException('Invalid signing key');
        return ['private' => $key, 'public' => $details['key']];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function localSources(): array {
    $sources = [];
    foreach (['isonex', 'lightstar'] as $supplier) {
        $m = localJson(localCatalog() . '/' . $supplier . '/current.json');
        if (($m['supplier'] ?? '') !== $supplier || !preg_match('/^[a-f0-9]{64}$/D', $m['revision'] ?? '')) throw new RuntimeException('Invalid catalog revision');
        $sources[] = $m;
    }
    return $sources;
}
function localInventory(bool $requireFresh = true): array {
    $items = [];
    foreach (localSources() as $source) {
        $checked = strtotime($source['checkedAt'] ?? '');
        if ($requireFresh && (!$checked || $checked < time() - 72 * 3600 || $checked > time() + 3600)) throw new RuntimeException('Catalog freshness check failed');
        $part = localJson(localCatalog() . '/' . $source['supplier'] . '/' . $source['revision'] . '/inventory.json');
        if (count($part) !== ($source['count'] ?? 0)) throw new RuntimeException('Inventory count mismatch');
        foreach ($part as $p) {
            if (!is_array($p) || !is_string($p['id'] ?? null) || isset($items[$p['id']])) throw new RuntimeException('Invalid inventory');
            $items[$p['id']] = $p;
        }
    }
    return $items;
}
final class LocalPriceChanged extends RuntimeException {
    public function __construct(public array $items, public float $total) { parent::__construct('Цена изменилась. Проверьте новую сумму и подтвердите заказ ещё раз.'); }
}
function localSelection(array $raw, array $inventory, string $origin, int $now): array {
    $fields = ['requestId', 'kind', 'service', 'delivery', 'installation', 'items'];
    if (array_diff(array_keys($raw), $fields)) throw new InvalidArgumentException('Передайте только состав корзины и способ получения.');
    $id = $raw['requestId'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id)) throw new InvalidArgumentException('Обновите форму и попробуйте снова.');
    $kind = $raw['kind'] ?? '';
    if (!in_array($kind, ['order', 'callback', 'service'], true)) throw new InvalidArgumentException('Выберите тип заявки.');
    $services = ['Монтаж люстры', 'Монтаж электроприборов', 'Выезд и подбор освещения', 'Авторское сопровождение'];
    $service = $raw['service'] ?? ''; $delivery = $raw['delivery'] ?? ''; $installation = $raw['installation'] ?? false;
    if (!is_string($service) || ($service !== '' && !in_array($service, $services, true)) || ($kind === 'service' && $service === '')) throw new InvalidArgumentException('Выберите услугу.');
    if (!is_string($delivery) || ($delivery !== '' && !in_array($delivery, ['pickup', 'moscow', 'russia'], true)) || ($kind === 'order' && $delivery === '')) throw new InvalidArgumentException('Выберите способ получения.');
    if (!is_bool($installation) || ($kind === 'order' && $installation && $delivery === 'russia')) throw new InvalidArgumentException('Проверьте монтаж и способ получения.');
    $rows = $raw['items'] ?? [];
    if (!is_array($rows) || !array_is_list($rows) || count($rows) > 50 || ($kind === 'order' && count($rows) < 1)) throw new InvalidArgumentException('В заказе должно быть от 1 до 50 разных товаров.');
    $items = []; $changed = []; $totalKopecks = 0; $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row) || array_diff(array_keys($row), ['id', 'quantity', 'expectedPrice']) || !is_string($row['id'] ?? null) || !is_int($row['quantity'] ?? null) || $row['quantity'] < 1 || !is_numeric($row['expectedPrice'] ?? null) || is_string($row['expectedPrice']) || !is_finite((float)$row['expectedPrice']) || $row['expectedPrice'] < 0) throw new InvalidArgumentException('Проверьте состав корзины.');
        if ($kind !== 'order') continue;
        $p = $inventory[$row['id']] ?? null;
        if (!$p || isset($seen[$row['id']]) || $row['quantity'] > $p['stock'] || $p['stock'] < 1) throw new InvalidArgumentException('Наличие товара изменилось. Обновите страницу и проверьте корзину.');
        $seen[$row['id']] = true;
        $item = ['id' => $p['id'], 'sku' => $p['sku'], 'name' => $p['name'], 'quantity' => $row['quantity'], 'price' => $p['price']];
        $items[] = $item; $totalKopecks += (int)round($p['price'] * 100) * $row['quantity'];
        $changed[] = $item + ['stock' => $p['stock'], 'previousPrice' => $row['expectedPrice']];
    }
    $total = $totalKopecks / 100;
    if ($kind === 'order') {
        if ($total < ($delivery === 'pickup' ? 150 : 5000)) throw new InvalidArgumentException($delivery === 'pickup' ? 'Минимальный заказ для самовывоза — 150 ₽.' : 'Минимальный заказ для доставки — 5 000 ₽.');
        foreach ($changed as $item) if (round($item['price'] * 100) !== round($item['previousPrice'] * 100)) throw new LocalPriceChanged($changed, $total);
    }
    return ['v' => 1, 'aud' => $origin, 'requestId' => $id, 'expires' => $now + 300, 'kind' => $kind,
        'service' => $service, 'delivery' => $kind === 'order' ? $delivery : '', 'installation' => $kind === 'order' && $installation,
        'items' => $items, 'total' => $total, 'prepayment' => $delivery === 'moscow' ? round($total * 50) / 100 : ($delivery === 'russia' ? $total : 0)];
}
function localEncode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function localSigned(array $ticket): array {
    $encoded = localEncode(json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $key = localKey();
    if (!openssl_sign($encoded, $signature, $key['private'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signing failed');
    return ['ticket' => $encoded, 'signature' => localEncode($signature), 'endpoint' => '/elstar-local-requests.php'];
}
