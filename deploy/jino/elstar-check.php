<?php
// Temporary installation check. No credentials, customer data, or database writes.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
$checks = [
    'PHP 8.4 или новее' => PHP_VERSION_ID >= 80400,
    'Подключение к MySQL (PDO)' => extension_loaded('pdo_mysql'),
    'HTTPS-запросы (cURL)' => extension_loaded('curl'),
    'Русский текст (mbstring)' => extension_loaded('mbstring'),
    'Проверка фотографий (fileinfo)' => extension_loaded('fileinfo'),
    'Защищённое соединение с этой страницей' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
];
$network = [];
if (extension_loaded('curl')) {
    // Fixed destinations only. GET checks the transport; no bot token or messages.
    foreach (['Telegram' => 'https://api.telegram.org/', 'Джино (сравнение)' => 'https://jino.ru/'] as $name => $url) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_WRITEFUNCTION => static fn($handle, $data) => strlen($data)]);
        curl_exec($curl);
        $code = curl_errno($curl);
        $http = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $network[] = ['name' => $name, 'error' => $code, 'http' => $http,
            'message' => $code === 0 ? 'Соединение установлено' : curl_error($curl)];
        if ($name === 'Telegram') $checks['Доступ к Telegram по HTTPS'] = $code === 0 && $http > 0;
        curl_close($curl);
    }
}
$ready = !in_array(false, $checks, true);
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ELSTAR — проверка хостинга</title>
<style>body{font:16px/1.6 system-ui,sans-serif;margin:0;padding:24px;color:#222;background:#fafafa}main{max-width:640px;margin:32px auto;background:white;padding:24px;border:1px solid #ddd;border-radius:12px}h1{font-size:26px}ul{padding:0;list-style:none}li{padding:12px 0;border-bottom:1px solid #eee}.ok{color:#276943}.fail{color:#ac2525}strong{display:block}small{color:#666} </style>
<main><h1>ELSTAR — проверка хостинга</h1><ul>
<?php foreach ($checks as $label => $passed): ?>
<li><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong><span class="<?= $passed ? 'ok' : 'fail' ?>"><?= $passed ? 'Готово' : 'Требуется настройка' ?></span></li>
<?php endforeach; ?>
</ul><h2>Диагностика соединения</h2>
<?php foreach ($network as $result): ?>
<p><strong><?= htmlspecialchars($result['name'], ENT_QUOTES, 'UTF-8') ?></strong>
<?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?><br>
Код cURL: <?= $result['error'] ?>; HTTP: <?= $result['http'] ?></p>
<?php endforeach; ?>
<p><?= $ready ? 'Технические функции доступны. Следующий шаг — подключение базы и установка обработчика.' : 'Перед установкой обработчика нужно выяснить причину отмеченных ошибок.' ?></p>
<small>Эта проверка не подключается к базе и не отправляет сообщения. Приём заказов ещё не настроен. После проверки удалите файл elstar-check.php.</small></main></html>
