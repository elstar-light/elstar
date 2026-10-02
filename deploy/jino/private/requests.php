<?php
declare(strict_types=1);

function elstarDecode(string $value): string {
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) throw new InvalidArgumentException('Обновите форму и попробуйте снова.');
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    if ($decoded === false) throw new InvalidArgumentException('Обновите форму и попробуйте снова.');
    return $decoded;
}

function elstarPayload(array $raw, array $ticket): array {
    $text = static function (string $key, int $max) use ($raw): string {
        $value = $raw[$key] ?? '';
        if (!is_string($value) || mb_strlen($value) > $max) throw new InvalidArgumentException('Проверьте поля заявки.');
        return trim($value);
    };
    $name = $text('name', 150); $phone = $text('phone', 40); $email = $text('email', 200);
    $address = $text('address', 500); $comment = $text('comment', 1500); $date = $text('date', 10);
    if (mb_strlen($name) < 2) throw new InvalidArgumentException('Укажите имя.');
    $digits = preg_replace('/\D/', '', $phone);
    if (!preg_match('/^\+?[\d\s()\-]+$/D', $phone) || strlen($digits) < 10 || strlen($digits) > 15) throw new InvalidArgumentException('Проверьте номер телефона.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Проверьте электронную почту.');
    if (($raw['consent'] ?? false) !== true || ($raw['consentVersion'] ?? '') !== '2026-10-02') throw new InvalidArgumentException('Подтвердите согласие на обработку заявки.');
    $kind = $ticket['kind'] ?? ''; $delivery = $ticket['delivery'] ?? ''; $installation = ($ticket['installation'] ?? false) === true;
    if (!in_array($kind, ['order', 'service', 'callback'], true)) throw new InvalidArgumentException('Обновите форму.');
    if (($raw['requestId'] ?? '') !== $ticket['requestId'] || ($raw['kind'] ?? '') !== $kind) throw new InvalidArgumentException('Обновите форму.');
    if ($kind === 'order' && (!in_array($delivery, ['pickup', 'moscow', 'russia'], true) || ($installation && $delivery === 'russia'))) throw new InvalidArgumentException('Проверьте способ получения.');
    if (($kind === 'service' || ($kind === 'order' && ($delivery !== 'pickup' || $installation))) && mb_strlen($address) < 8) throw new InvalidArgumentException('Укажите полный адрес.');
    if ($date !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Moscow'));
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $parsed < $today) throw new InvalidArgumentException('Проверьте дату выезда.');
    }
    return ['kind' => $kind, 'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address,
        'comment' => $comment, 'date' => $date, 'service' => $ticket['service'] ?? '', 'delivery' => $delivery,
        'installation' => $installation, 'items' => $ticket['items'] ?? [], 'total' => $ticket['total'] ?? 0,
        'prepayment' => $ticket['prepayment'] ?? 0, 'consent' => true, 'consent_version' => '2026-10-02'];
}

function elstarNotify(array $config, string $reference, string $kind): bool {
    if (empty($config['telegram_bot_token']) || empty($config['telegram_chat_id'])) return false;
    // Customer details/photos remain in Jino. Telegram receives only the reference.
    $labels = ['order' => 'Новый заказ', 'service' => 'Заявка на услугу', 'callback' => 'Обратный звонок'];
    $curl = curl_init('https://api.telegram.org/bot' . $config['telegram_bot_token'] . '/sendMessage');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $config['telegram_chat_id'], 'text' => 'ELSTAR · ' . $labels[$kind] . "\n№ " . $reference], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return $status === 200 && is_string($body) && (json_decode($body, true)['ok'] ?? false) === true;
}

function elstarReceive(array $config, string $private): never {
    $db = null; $moved = []; $saved = false;
    try {
        // Tickets contain trusted product prices only, never customer details.
        $encoded = $_POST['ticket'] ?? ''; $signature = $_POST['signature'] ?? ''; $json = $_POST['payload'] ?? '';
        if (!is_string($encoded) || strlen($encoded) > 100000 || !is_string($signature) || strlen($signature) > 1024 || !is_string($json) || strlen($json) > 16000) throw new InvalidArgumentException('Проверьте заявку.');
        $key = openssl_pkey_get_public($config['ticket_public_key'] ?? '');
        if (!$key) throw new RuntimeException('ticket configuration');
        if (openssl_verify($encoded, elstarDecode($signature), $key, OPENSSL_ALGO_SHA256) !== 1) throw new InvalidArgumentException('Обновите форму и проверьте корзину.');
        $ticket = json_decode(elstarDecode($encoded), true, 32, JSON_THROW_ON_ERROR);
        $raw = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($ticket) || !is_array($raw)) throw new InvalidArgumentException('Проверьте заявку.');
        if (($ticket['v'] ?? null) !== 1 || ($ticket['aud'] ?? '') !== $config['origin'] || !is_int($ticket['expires'] ?? null) || $ticket['expires'] < time() || $ticket['expires'] > time() + 600) throw new InvalidArgumentException('Проверка корзины устарела. Отправьте форму ещё раз.');
        $id = $ticket['requestId'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id)) throw new InvalidArgumentException('Обновите форму.');
        if (!empty($raw['website'])) throw new InvalidArgumentException('Не удалось отправить заявку.');
        $payload = elstarPayload($raw, $ticket);
        $uploads = $_FILES['photos'] ?? ['error' => []]; $photos = [];
        if (!is_array($uploads['error'])) throw new InvalidArgumentException('Проверьте фотографии.');
        foreach ($uploads['error'] as $i => $error) {
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($uploads['tmp_name'][$i]) || $uploads['size'][$i] > 5 * 1024 * 1024 || count($photos) >= 3) throw new InvalidArgumentException('Прикрепите до 3 фото по 5 МБ.');
            $tmp = $uploads['tmp_name'][$i]; $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$extension || !getimagesize($tmp)) throw new InvalidArgumentException('Прикрепите изображения JPG, PNG или WebP.');
            $photos[] = ['tmp' => $tmp, 'extension' => $extension, 'hash' => hash_file('sha256', $tmp)];
        }
        if ($photos && $payload['kind'] !== 'service' && !$payload['installation']) throw new InvalidArgumentException('Фото доступны для монтажа и выезда.');
        $fingerprint = hash('sha256', json_encode([$payload, array_column($photos, 'hash')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $db = new PDO('mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4', $config['db_user'], $config['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $source = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $config['db_password']);
        $db->beginTransaction();
        // Serialize submissions from one source, including simultaneous retries.
        $query = $db->prepare('INSERT IGNORE INTO elstar_rate_limits (source_hash,window_start,attempts) VALUES (?, ?, 0)'); $query->execute([$source, time()]);
        $query = $db->prepare('SELECT window_start,attempts FROM elstar_rate_limits WHERE source_hash=? FOR UPDATE'); $query->execute([$source]); $rate = $query->fetch(PDO::FETCH_ASSOC);
        $query = $db->prepare('SELECT reference,fingerprint,notification_status FROM elstar_requests WHERE id=? FOR UPDATE'); $query->execute([$id]); $prior = $query->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            $db->rollBack();
            if (!hash_equals($prior['fingerprint'], $fingerprint)) respond(['error' => 'Заявка с этим номером уже сохранена. Обновите форму для новой заявки.'], 409);
            elstarConfirmation($prior['reference'], $prior['notification_status'] === 'sent');
        }
        $fresh = (int) $rate['window_start'] <= time() - 600;
        if (!$fresh && (int) $rate['attempts'] >= 5) { $db->rollBack(); respond(['error' => 'Слишком много заявок. Попробуйте через 10 минут.'], 429); }
        $query = $db->prepare('UPDATE elstar_rate_limits SET window_start=?,attempts=? WHERE source_hash=?');
        $query->execute([$fresh ? time() : $rate['window_start'], $fresh ? 1 : (int) $rate['attempts'] + 1, $source]);
        $photoKeys = [];
        if ($photos) {
            $photoPrefix = 'photos/' . $id . '/' . bin2hex(random_bytes(12));
            $folder = $private . '/' . $photoPrefix;
            if (!is_dir($folder) && !mkdir($folder, 0700, true)) throw new RuntimeException('photo storage');
            foreach ($photos as $i => $photo) {
                $relative = $photoPrefix . '/' . $i . '.' . $photo['extension']; $target = $private . '/' . $relative;
                if (!move_uploaded_file($photo['tmp'], $target)) throw new RuntimeException('photo storage');
                $moved[] = $target; chmod($target, 0600); $photoKeys[] = $relative;
            }
        }
        $reference = 'EL-' . gmdate('Ymd') . '-' . strtoupper(str_replace('-', '', $id));
        $query = $db->prepare("INSERT INTO elstar_requests (id,reference,kind,fingerprint,payload,photo_keys,manager_note,source_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,'',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $query->execute([$id, $reference, $payload['kind'], $fingerprint, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($photoKeys, JSON_THROW_ON_ERROR), $source]);
        $db->commit(); $saved = true;
        $notified = elstarNotify($config, $reference, $payload['kind']);
        $query = $db->prepare('UPDATE elstar_requests SET notification_status=?,updated_at=UTC_TIMESTAMP() WHERE id=?'); $query->execute([$notified ? 'sent' : 'failed', $id]);
        elstarConfirmation($reference, $notified);
    } catch (Throwable $error) {
        if ($db && $db->inTransaction()) $db->rollBack();
        if ($saved) elstarConfirmation($reference, false);
        foreach ($moved as $file) @unlink($file);
        if ($error instanceof InvalidArgumentException) respond(['error' => $error->getMessage()], 400);
        if ($error instanceof JsonException) respond(['error' => 'Проверьте заявку и отправьте форму ещё раз.'], 400);
        throw $error;
    }
}

function elstarConfirmation(string $reference, bool $notified): never {
    respond(['reference' => $reference, 'notified' => $notified, 'message' => $notified
        ? 'Заявка получена! Свяжемся с вами с 10:00 до 20:00.'
        : 'Заявка сохранена. Уведомление не доставлено — позвоните: +7 (925) 908-89-88 и назовите номер заявки.']);
}
