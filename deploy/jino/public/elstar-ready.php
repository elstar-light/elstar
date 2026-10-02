<?php
declare(strict_types=1);
// Read-only installation check. Remove from the public directory after setup.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
$checks = ['PHP 8.4 или новее' => PHP_VERSION_ID >= 80400];
foreach (['pdo_mysql', 'openssl', 'fileinfo', 'mbstring', 'curl'] as $extension) {
    $checks[$extension] = $extension === 'pdo_mysql'
        ? class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true)
        : extension_loaded($extension);
}
$private = dirname(__DIR__, 2) . '/elstar-private';
$checks['Закрытая папка настроек'] = is_file($private . '/config.php');
$checks['Обработчик заявок'] = is_file(__DIR__ . '/elstar-requests.php') && is_file($private . '/requests.php');
$checks['Подключение к базе'] = false;
$checks['Таблицы заявок'] = false;
$checks['Ключ проверки корзины'] = false;
if ($checks['Закрытая папка настроек']) {
    try {
        $config = require $private . '/config.php';
        $db = new PDO('mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $checks['Подключение к базе'] = true;
        $db->query('SELECT id FROM elstar_requests LIMIT 0');
        $db->query('SELECT source_hash FROM elstar_rate_limits LIMIT 0');
        $checks['Таблицы заявок'] = true;
        $checks['Ключ проверки корзины'] = (bool) openssl_pkey_get_public($config['ticket_public_key'] ?? '');
    } catch (Throwable $error) {
        // No credentials, paths, SQL errors or customer records are exposed.
    }
}
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ELSTAR — проверка подключения</title>
<style>body{font:17px/1.6 system-ui;margin:40px auto;padding:0 20px;max-width:650px;color:#252525}h1{font-size:28px}ul{padding:0;list-style:none}li{padding:12px 0;border-bottom:1px solid #eee}b{float:right}.ok{color:#287044}.wait{color:#886c2e}</style>
<h1>ELSTAR — проверка подключения</h1><p>Эта страница проверяет настройки. Заявки не создаются, уведомления не отправляются.</p><ul>
<?php foreach ($checks as $label => $ok): ?><li><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?><b class="<?= $ok ? 'ok' : 'wait' ?>"><?= $ok ? 'Готово' : 'Нужно настроить' ?></b></li><?php endforeach ?>
</ul><p>Проверка связи с Telegram выполняется отдельно. После завершения настройки эту страницу нужно удалить.</p></html>
