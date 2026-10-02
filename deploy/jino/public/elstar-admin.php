<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(400);
    exit('Откройте кабинет по защищённой ссылке HTTPS.');
}
try {
    $private = dirname(__DIR__, 2) . '/elstar-private';
    if (!is_file($private . '/config.php') || !is_file($private . '/admin.php')) throw new RuntimeException('not installed');
    $config = require $private . '/config.php';
    require $private . '/admin.php';
    elstarAdminRun($config, $private);
} catch (Throwable $error) {
    $diagnostic = 'A-' . get_class($error) . '-LINE-' . $error->getLine();
    if ($error instanceof ParseError) $diagnostic = 'A-SYNTAX-' . $error->getLine();
    elseif ($error instanceof PDOException) {
        $driverCode = $error->errorInfo[1] ?? 0;
        $diagnostic = 'A-DB-' . (is_int($driverCode) ? $driverCode : 0);
    } elseif ($error->getMessage() === 'session unavailable' || $error->getMessage() === 'session regeneration') $diagnostic = 'A-SESSION';
    elseif ($error->getMessage() === 'not installed') $diagnostic = 'A-FILES';
    http_response_code(503);
    echo '<p>Код проверки: ' . htmlspecialchars($diagnostic, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ELSTAR</title><p>Кабинет временно недоступен. Проверьте установку файлов и подключение к базе, затем обновите страницу.</p></html>';
}
