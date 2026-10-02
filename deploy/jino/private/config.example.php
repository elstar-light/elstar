<?php
declare(strict_types=1);
// Save as elstar-private/config.php in the hosting account root, OUTSIDE domains/.
// Never upload this file with real passwords into the public site directory.
return [
    'enabled' => false, // Enable only after database, signed tickets and consent are checked.
    'origin' => 'https://elstar-light.elstar1-ru.chatgpt.site',
    'db_host' => 'localhost',
    'db_name' => 'j60934287',
    'db_user' => 'j60934287_orders',
    'db_password' => '',
    'ticket_public_key' => is_file(__DIR__ . '/ticket-key.php') ? (require __DIR__ . '/ticket-key.php') : '',
    'telegram_bot_token' => '',
    'telegram_chat_id' => '',
];
