<?php
declare(strict_types=1);

function elstarAdminEscape(mixed $value): string {
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function elstarAdminMoney(mixed $value): string {
    $number = is_numeric($value) ? (float) $value : 0;
    return number_format($number, floor($number) === $number ? 0 : 2, ',', ' ') . ' ₽';
}
function elstarAdminDate(string $value): string {
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
}
function elstarAdminUuid(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
}
function elstarAdminPassword(array $record, string $password): bool {
    return hash_equals($record['hash'], hash_pbkdf2('sha256', $password, hex2bin($record['salt']), $record['iterations'], 64));
}
function elstarAdminPasswordRecord(mixed $record): bool {
    return is_array($record) && ($record['version'] ?? null) === 1 && ($record['algorithm'] ?? '') === 'pbkdf2-sha256'
        && ($record['iterations'] ?? null) === 600000 && is_string($record['salt'] ?? null)
        && preg_match('/^[a-f0-9]{48}$/D', $record['salt']) === 1 && is_string($record['hash'] ?? null)
        && preg_match('/^[a-f0-9]{64}$/D', $record['hash']) === 1;
}
function elstarAdminDb(array $config): PDO {
    return new PDO('mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4', $config['db_user'], $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
function elstarAdminLoginAttempt(PDO $db, string $source): bool {
    $db->beginTransaction();
    try {
        $query = $db->prepare('INSERT IGNORE INTO elstar_rate_limits (source_hash,window_start,attempts) VALUES (?, ?, 0)'); $query->execute([$source, time()]);
        $query = $db->prepare('SELECT window_start,attempts FROM elstar_rate_limits WHERE source_hash=? FOR UPDATE'); $query->execute([$source]); $rate = $query->fetch(PDO::FETCH_ASSOC);
        $fresh = (int) $rate['window_start'] <= time() - 600;
        if (!$fresh && (int) $rate['attempts'] >= 5) { $db->rollBack(); return false; }
        $query = $db->prepare('UPDATE elstar_rate_limits SET window_start=?,attempts=? WHERE source_hash=?');
        $query->execute([$fresh ? time() : $rate['window_start'], $fresh ? 1 : (int) $rate['attempts'] + 1, $source]);
        $db->commit(); return true;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
function elstarAdminRedirect(string $query = ''): never {
    header('Location: elstar-admin.php' . ($query !== '' ? '?' . $query : ''), true, 303); exit;
}
function elstarAdminCsrf(): string {
    return '<input type="hidden" name="csrf" value="' . elstarAdminEscape($_SESSION['csrf']) . '">';
}
function elstarAdminPhoto(PDO $db, string $private): never {
    $id = $_GET['id'] ?? ''; $index = filter_var($_GET['index'] ?? null, FILTER_VALIDATE_INT);
    if (!elstarAdminUuid($id) || $index === false || $index === null || $index < 0 || $index > 2) { http_response_code(404); exit; }
    $query = $db->prepare('SELECT photo_keys FROM elstar_requests WHERE id=?'); $query->execute([$id]); $row = $query->fetch(PDO::FETCH_ASSOC);
    $keys = $row ? json_decode($row['photo_keys'], true) : [];
    $key = is_array($keys) ? ($keys[$index] ?? '') : '';
    if (!is_string($key) || preg_match('#^photos/' . preg_quote($id, '#') . '/[a-f0-9]{24}/[0-2]\.(jpg|png|webp)$#D', $key) !== 1) { http_response_code(404); exit; }
    $root = realpath($private . '/photos'); $path = realpath($private . '/' . $key);
    if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit; }
    session_write_close();
    header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="photo.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
    readfile($path); exit;
}
function elstarAdminRun(array $config, string $private): void {
    $statuses = ['new' => 'Новая', 'confirmed' => 'Подтверждена', 'in_progress' => 'В работе', 'completed' => 'Завершена', 'cancelled' => 'Отменена'];
    $payments = ['unpaid' => 'Не оплачено', 'partial' => 'Частично оплачено', 'paid' => 'Оплачено'];
    $kinds = ['order' => 'Заказ', 'service' => 'Услуга', 'callback' => 'Обратный звонок'];
    $record = is_file($private . '/admin-password.php') ? (require $private . '/admin-password.php') : null;
    $configured = elstarAdminPasswordRecord($record);
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    session_name('ELSTAR_ADMIN');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/elstar-admin.php', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) throw new RuntimeException('session unavailable');
    $passwordVersion = $configured ? hash('sha256', $record['salt'] . ':' . $record['hash']) : '';
    $auth = $_SESSION['auth'] ?? null;
    $authenticated = $configured && is_array($auth) && ($auth['password_version'] ?? '') === $passwordVersion
        && ($auth['created_at'] ?? 0) > time() - 28800 && ($auth['last_seen'] ?? 0) > time() - 1800;
    if (!$authenticated) unset($_SESSION['auth']);
    else $_SESSION['auth']['last_seen'] = time();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $error = ''; $message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
    $db = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://3d2ef918ae94.hosting.myjino.ru') {
            http_response_code(403); $error = 'Не удалось подтвердить адрес страницы. Откройте кабинет по прямой ссылке в отдельной вкладке браузера.';
        } elseif (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403); $error = 'Сеанс входа не сохранился. Разрешите cookies для этого сайта, затем обновите страницу и повторите вход.';
        } else {
            $action = $_POST['action'] ?? '';
            if ($action === 'login' && !$authenticated && $configured) {
                $db = elstarAdminDb($config);
                $source = hash_hmac('sha256', 'admin:' . ($_SERVER['REMOTE_ADDR'] ?? ''), $record['salt']);
                $password = $_POST['password'] ?? '';
                if (!elstarAdminLoginAttempt($db, $source)) { http_response_code(429); $error = 'Слишком много попыток. Попробуйте через 10 минут.'; }
                elseif (!is_string($password) || strlen($password) > 512 || !elstarAdminPassword($record, $password)) { http_response_code(401); $error = 'Не удалось войти. Проверьте пароль.'; }
                else {
                    $query = $db->prepare('DELETE FROM elstar_rate_limits WHERE source_hash=?'); $query->execute([$source]);
                    if (!session_regenerate_id(true)) throw new RuntimeException('session regeneration');
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    $_SESSION['auth'] = ['created_at' => time(), 'last_seen' => time(), 'password_version' => $passwordVersion];
                    elstarAdminRedirect();
                }
            } elseif (!$authenticated) {
                http_response_code(401); $error = 'Войдите в кабинет.';
            } elseif ($action === 'logout') {
                $_SESSION = []; session_destroy();
                setcookie('ELSTAR_ADMIN', '', ['expires' => time() - 3600, 'path' => '/elstar-admin.php', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
                elstarAdminRedirect();
            } elseif ($action === 'update') {
                $id = $_POST['id'] ?? ''; $status = $_POST['status'] ?? ''; $payment = $_POST['payment'] ?? ''; $note = $_POST['note'] ?? '';
                if (!elstarAdminUuid($id) || !is_string($status) || !isset($statuses[$status]) || !is_string($payment) || !isset($payments[$payment]) || !is_string($note) || mb_strlen($note) > 4000) {
                    $error = 'Проверьте статус, оплату и заметку.';
                } else {
                    $db = elstarAdminDb($config);
                    $query = $db->prepare('UPDATE elstar_requests SET status=?,payment_status=?,manager_note=?,updated_at=UTC_TIMESTAMP() WHERE id=?'); $query->execute([$status, $payment, trim($note), $id]);
                    $_SESSION['message'] = 'Изменения сохранены.'; elstarAdminRedirect(http_build_query(['id' => $id]));
                }
            } elseif ($action === 'notify') {
                $id = $_POST['id'] ?? '';
                if (!elstarAdminUuid($id) || empty($config['telegram_bot_token']) || empty($config['telegram_chat_id'])) $error = 'Уведомления Telegram пока не подключены.';
                else {
                    $db = elstarAdminDb($config);
                    $query = $db->prepare("UPDATE elstar_requests SET notification_status='sending',updated_at=UTC_TIMESTAMP() WHERE id=? AND notification_status='failed'"); $query->execute([$id]);
                    if ($query->rowCount() === 1) {
                        $query = $db->prepare('SELECT reference,kind FROM elstar_requests WHERE id=?'); $query->execute([$id]); $row = $query->fetch(PDO::FETCH_ASSOC);
                        require_once $private . '/requests.php'; $sent = false;
                        try { $sent = elstarNotify($config, $row['reference'], $row['kind']); }
                        finally { $query = $db->prepare('UPDATE elstar_requests SET notification_status=?,updated_at=UTC_TIMESTAMP() WHERE id=?'); $query->execute([$sent ? 'sent' : 'failed', $id]); }
                        $_SESSION['message'] = $sent ? 'Уведомление отправлено.' : 'Уведомление не доставлено. Заявка сохранена в кабинете.';
                    } else $_SESSION['message'] = 'Статус уведомления изменился. Проверьте заявку.';
                    elstarAdminRedirect(http_build_query(['id' => $id]));
                }
            } else { http_response_code(400); $error = 'Неизвестное действие.'; }
        }
    }
    if (!$authenticated && ($_GET['action'] ?? '') === 'photo') http_response_code(401);
    if ($authenticated) {
        $db ??= elstarAdminDb($config);
        if (($_GET['action'] ?? '') === 'photo') elstarAdminPhoto($db, $private);
        $search = is_string($_GET['q'] ?? '') ? mb_substr(trim($_GET['q'] ?? ''), 0, 80) : '';
        $filter = is_string($_GET['status'] ?? '') && isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : '';
        $page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
        $where = []; $params = [];
        if ($search !== '') {
            $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $digits = preg_replace('/\D/', '', $search);
            $match = ["reference LIKE ? ESCAPE '!'", "JSON_UNQUOTE(JSON_EXTRACT(payload,'$.phone')) LIKE ? ESCAPE '!'"]; $params = [$like, $like];
            if ($digits !== '') {
                $match[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.phone')),' ',''),'-',''),'(',''),')',''),'+','') LIKE ?"; $params[] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $match) . ')';
        }
        if ($filter !== '') { $where[] = 'status=?'; $params[] = $filter; }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $query = $db->prepare('SELECT COUNT(*) FROM elstar_requests' . $sqlWhere); $query->execute($params); $count = (int) $query->fetchColumn();
        $pages = max(1, (int) ceil($count / 20)); $page = min($page, $pages); $offset = ($page - 1) * 20;
        $query = $db->prepare('SELECT id,reference,kind,payload,status,payment_status,created_at FROM elstar_requests' . $sqlWhere . ' ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET ' . $offset); $query->execute($params); $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $selected = null; $details = []; $photoKeys = [];
        if (elstarAdminUuid($_GET['id'] ?? '')) {
            $query = $db->prepare('SELECT * FROM elstar_requests WHERE id=?'); $query->execute([$_GET['id']]); $selected = $query->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($selected) { $details = json_decode($selected['payload'], true, 32, JSON_THROW_ON_ERROR); $photoKeys = json_decode($selected['photo_keys'], true, 8, JSON_THROW_ON_ERROR); }
        }
    }
    ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ELSTAR — личный кабинет</title>
<style>
:root{color-scheme:light;--gold:#9e7c36;--line:#e7e2d9;--ink:#252522}*{box-sizing:border-box}body{margin:0;background:#faf9f7;color:var(--ink);font:16px/1.5 system-ui,-apple-system,sans-serif}a{color:var(--gold);text-underline-offset:3px}header{background:#fff;border-bottom:1px solid var(--line);padding:22px max(20px,calc((100vw - 1320px)/2));display:flex;gap:24px;align-items:center;justify-content:space-between}header strong{font-size:26px;letter-spacing:3px}header p{margin:0;color:#65655f}main{max-width:1360px;padding:30px 20px;margin:auto}.panel{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}.login{max-width:460px;margin:7vh auto}h1{font-size:28px;margin:0 0 20px}h2{font-size:22px;margin:0 0 16px}h3{font-size:18px}p{overflow-wrap:anywhere}.muted{color:#66665e}.alert{padding:14px 18px;background:#fff5e6;border:1px solid #e8cc94;border-radius:8px;margin-bottom:20px}.success{background:#eef7ef;border-color:#bed8be}label{display:block;margin-bottom:16px;font-size:16px}input,select,textarea,button{font:inherit}input,select,textarea{display:block;width:100%;padding:11px 12px;border:1px solid #c8c4bc;border-radius:6px;margin-top:6px;min-width:0}button,.button{display:inline-block;min-height:44px;padding:10px 18px;border:1px solid var(--gold);border-radius:6px;background:var(--gold);color:#fff;cursor:pointer;text-decoration:none}button.secondary{color:var(--ink);background:#fff}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid #a08343;outline-offset:3px}.filters{display:flex;align-items:end;gap:16px;margin:0 0 24px}.filters label{flex:1;margin:0}.columns{display:grid;grid-template-columns:minmax(280px,.85fr) minmax(0,1.4fr);gap:24px;align-items:start}.request-list{display:grid;gap:12px}.request-card{display:block;color:inherit;text-decoration:none;padding:18px;border:1px solid var(--line);border-radius:10px;background:#fff}.request-card.active{border-color:var(--gold);box-shadow:0 0 0 1px var(--gold)}.reference{display:block;font-size:14px;overflow-wrap:anywhere}.request-card h3{margin:10px 0 2px}.request-card p{margin:5px 0}.tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.tag{display:inline-block;padding:3px 9px;border-radius:5px;background:#f2efe8;font-size:14px}.facts{display:grid;grid-template-columns:130px minmax(0,1fr);gap:10px 18px}.facts dt{color:#66665e}.facts dd{margin:0;overflow-wrap:anywhere;white-space:pre-wrap}.items{padding-left:20px}.items li{padding:12px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.photo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.photo-grid img{display:block;width:100%;height:130px;object-fit:cover;border-radius:6px}.pagination{display:flex;gap:18px;align-items:center;margin-top:20px}.details-form{margin-top:24px;padding-top:22px;border-top:1px solid var(--line)}.note{white-space:pre-wrap}.login button{width:100%}@media(max-width:850px){.columns{grid-template-columns:1fr}.filters{flex-wrap:wrap}.filters label{flex-basis:100%}header{padding:18px 20px;align-items:flex-start}header p{font-size:14px}main{padding-top:20px}.panel{padding:20px}.facts{grid-template-columns:1fr;gap:4px}.facts dd{margin-bottom:10px}h1{font-size:24px}}@media(prefers-reduced-motion:no-preference){button,a{transition:border-color .15s,background .15s}}
header{flex-wrap:wrap}h1,h2,h3{overflow-wrap:anywhere}
</style></head><body><header><div><strong>ELSTAR</strong><p>Личный кабинет</p></div><?php if ($authenticated): ?><form method="post"><?= elstarAdminCsrf() ?><input type="hidden" name="action" value="logout"><button class="secondary">Выйти</button></form><?php endif ?></header><main>
<?php if ($error !== ''): ?><p class="alert" role="alert"><?= elstarAdminEscape($error) ?></p><?php endif ?>
<?php if ($message !== ''): ?><p class="alert success" role="status"><?= elstarAdminEscape($message) ?></p><?php endif ?>
<?php if (!$authenticated): ?><section class="panel login"><h1>Вход в кабинет</h1>
<?php if (!$configured): ?><p>Сначала загрузите файл пароля <strong>admin-password.php</strong> в закрытую папку настроек ELSTAR.</p>
<?php else: ?><form method="post"><?= elstarAdminCsrf() ?><input type="hidden" name="action" value="login"><label>Пароль<input type="password" name="password" required maxlength="128" autocomplete="current-password" autofocus></label><button>Войти</button></form><?php endif ?></section>
<?php else: ?><h1>Заявки</h1><?php if (!($config['enabled'] ?? false)): ?><p class="alert">Приём заявок с сайта ещё не включён.</p><?php endif ?>
<form class="filters" method="get"><label>Телефон или номер заявки<input name="q" value="<?= elstarAdminEscape($search) ?>" maxlength="80" placeholder="Поиск"></label><label>Статус<select name="status"><option value="">Все заявки</option><?php foreach ($statuses as $value => $label): ?><option value="<?= $value ?>" <?= $filter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label><button>Найти</button><a href="elstar-admin.php">Сбросить</a></form>
<p class="muted">Найдено заявок: <?= $count ?></p><div class="columns"><section aria-label="Список заявок"><div class="request-list">
<?php foreach ($rows as $row): $p = json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR); ?><a class="request-card <?= ($selected['id'] ?? '') === $row['id'] ? 'active' : '' ?>" href="?<?= elstarAdminEscape(http_build_query(['id' => $row['id'], 'q' => $search, 'status' => $filter, 'page' => $page])) ?>"><strong class="reference"><?= elstarAdminEscape($row['reference']) ?></strong><p class="muted"><?= elstarAdminEscape($kinds[$row['kind']] ?? 'Заявка') ?> · <?= elstarAdminEscape(elstarAdminDate($row['created_at'])) ?></p><h3><?= elstarAdminEscape($p['name'] ?? '') ?></h3><p><?= elstarAdminEscape($p['phone'] ?? '') ?></p><?php if ($row['kind'] === 'order'): ?><strong><?= elstarAdminMoney($p['total'] ?? 0) ?></strong><?php endif ?><div class="tags"><span class="tag"><?= elstarAdminEscape($statuses[$row['status']] ?? $row['status']) ?></span><span class="tag"><?= elstarAdminEscape($payments[$row['payment_status']] ?? $row['payment_status']) ?></span></div></a><?php endforeach ?>
<?php if (!$rows): ?><div class="panel"><h2><?= $search !== '' || $filter !== '' ? 'Ничего не найдено' : 'Пока нет заявок' ?></h2><p class="muted"><?= $search !== '' || $filter !== '' ? 'Попробуйте другой телефон или статус.' : 'Новые заказы и обращения будут появляться здесь.' ?></p></div><?php endif ?></div>
<?php if ($pages > 1): ?><nav class="pagination" aria-label="Страницы заявок"><?php if ($page > 1): ?><a href="?<?= elstarAdminEscape(http_build_query(['q' => $search, 'status' => $filter, 'page' => $page - 1])) ?>">Предыдущая</a><?php endif ?><span><?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a href="?<?= elstarAdminEscape(http_build_query(['q' => $search, 'status' => $filter, 'page' => $page + 1])) ?>">Следующая</a><?php endif ?></nav><?php endif ?></section>
<section class="panel" aria-label="Детали заявки"><?php if (!$selected): ?><h2>Выберите заявку</h2><p class="muted">Здесь будут контакты, товары, фотографии и заметки.</p><?php else: ?><h2><?= elstarAdminEscape($kinds[$selected['kind']] ?? 'Заявка') ?></h2><p class="reference"><?= elstarAdminEscape($selected['reference']) ?></p><dl class="facts"><dt>Создана</dt><dd><?= elstarAdminEscape(elstarAdminDate($selected['created_at'])) ?></dd><dt>Имя</dt><dd><?= elstarAdminEscape($details['name'] ?? '') ?></dd><dt>Телефон</dt><dd><a href="tel:<?= elstarAdminEscape(preg_replace('/[^+0-9]/', '', $details['phone'] ?? '')) ?>"><?= elstarAdminEscape($details['phone'] ?? '') ?></a></dd>
<?php foreach (['email' => 'Почта', 'address' => 'Адрес', 'service' => 'Услуга', 'date' => 'Дата выезда', 'comment' => 'Комментарий'] as $field => $label): if (!empty($details[$field])): ?><dt><?= $label ?></dt><dd><?= elstarAdminEscape($details[$field]) ?></dd><?php endif; endforeach ?>
<?php if (!empty($details['delivery'])): ?><dt>Получение</dt><dd><?= elstarAdminEscape(['pickup' => 'Самовывоз', 'moscow' => 'Москва / МО', 'russia' => 'Другой регион России'][$details['delivery']] ?? '') ?></dd><?php endif ?>
<?php if (!empty($details['installation'])): ?><dt>Установка</dt><dd>Нужна установка</dd><?php endif ?>
<?php if ($selected['kind'] === 'order'): ?><dt>Товары</dt><dd><?= elstarAdminMoney($details['total'] ?? 0) ?></dd><dt>Предоплата</dt><dd><?= elstarAdminMoney($details['prepayment'] ?? 0) ?></dd><?php endif ?>
<dt>Уведомление</dt><dd><?= elstarAdminEscape(['sent' => 'Доставлено в Telegram', 'failed' => 'Не доставлено', 'sending' => 'Отправляется', 'pending' => 'Ожидает отправки'][$selected['notification_status']] ?? 'Не доставлено') ?></dd></dl>
<?php if (!empty($details['items'])): ?><h3>Состав заказа</h3><ul class="items"><?php foreach ($details['items'] as $item): ?><li><strong><?= elstarAdminEscape($item['name'] ?? '') ?></strong><br><span class="muted">Артикул: <?= elstarAdminEscape($item['sku'] ?? '') ?></span><br><?= elstarAdminEscape($item['quantity'] ?? '') ?> шт. × <?= elstarAdminMoney($item['price'] ?? 0) ?></li><?php endforeach ?></ul><?php endif ?>
<?php if ($photoKeys): ?><h3>Фотографии</h3><div class="photo-grid"><?php foreach (array_slice($photoKeys, 0, 3) as $i => $key): $url = '?' . http_build_query(['action' => 'photo', 'id' => $selected['id'], 'index' => $i]); ?><a href="<?= elstarAdminEscape($url) ?>" target="_blank" rel="noopener"><img src="<?= elstarAdminEscape($url) ?>" alt="Фото к заявке <?= $i + 1 ?>" loading="lazy"></a><?php endforeach ?></div><?php endif ?>
<form method="post" class="details-form"><?= elstarAdminCsrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= elstarAdminEscape($selected['id']) ?>"><label>Статус<select name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?= $value ?>" <?= $selected['status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label><label>Оплата<select name="payment"><?php foreach ($payments as $value => $label): ?><option value="<?= $value ?>" <?= $selected['payment_status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label><label>Заметка для себя<textarea name="note" rows="5" maxlength="4000"><?= elstarAdminEscape($selected['manager_note']) ?></textarea></label><button>Сохранить изменения</button></form>
<?php if ($selected['notification_status'] === 'failed' && !empty($config['telegram_bot_token']) && !empty($config['telegram_chat_id'])): ?><form method="post" class="details-form"><?= elstarAdminCsrf() ?><input type="hidden" name="action" value="notify"><input type="hidden" name="id" value="<?= elstarAdminEscape($selected['id']) ?>"><button class="secondary">Повторить уведомление</button></form><?php endif ?>
<?php endif ?></section></div><?php endif ?></main></body></html>
<?php
}
