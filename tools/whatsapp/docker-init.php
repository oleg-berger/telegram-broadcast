<?php
declare(strict_types=1);

use Broadcast\Infrastructure\Runtime\ProcessLock;
use Broadcast\Infrastructure\SqliteStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/TelegramTime.php';
umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): never { throw new ErrorException('Initialization failed.', 0, $severity); });
try {
    $path = getenv('DATABASE_PATH');
    if (!is_string($path) || $path === '' || !is_file($path)) { throw new RuntimeException('Migrated database required.'); }
    $locks = [];
    foreach (['poll', 'worker', 'whatsapp-poll'] as $role) {
        $locks[] = ProcessLock::acquire(dirname($path) . '/runtime', $role);
    }
    $store = new SqliteStore($path);
    try {
        if (array_sum($store->inspectBusinessData()) === 0 && $store->offset() === 0 && $store->channelCursor('whatsapp') === 0) {
            // A fresh test installation must not replay messages from yesterday's session.
            $config = Broadcast\Infrastructure\Runtime\Config::fromEnvironment();
            $cutoff = Broadcast\Local\TelegramTime::cutoff(new Broadcast\Infrastructure\Http\CurlHttpClient(), $config->telegramToken);
            $store->resetBusinessData($cutoff);
        }
    } finally {
        $store->close();
    }
    echo "whatsapp_test_initialized\n";
} catch (Throwable $failure) {
    echo 'whatsapp_test_init_failed; exception=' . $failure::class . PHP_EOL;
    exit(1);
}
