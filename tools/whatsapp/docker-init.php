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

function initialized(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    try {
        $db->busyTimeout(5000);
        $tables = $db->querySingle("SELECT COUNT(*) FROM sqlite_schema WHERE type='table' AND name IN ('migrations','runtime_state')");
        if ($tables !== 2 || $db->querySingle("SELECT value FROM runtime_state WHERE name='whatsapp_test_initialized'") !== 1) {
            return false;
        }
        $versions = $db->query('SELECT version FROM migrations');
        $applied = [];
        while ($row = $versions->fetchArray(SQLITE3_NUM)) {
            $applied[] = $row[0];
        }
        $expected = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql'));
        return array_diff($expected, $applied) === [];
    } finally {
        $db->close();
    }
}

try {
    $path = getenv('DATABASE_PATH');
    if ($path === false || trim($path) === '' || $path === ':memory:') {
        throw new RuntimeException('Persistent database required.');
    }
    if (($argv[1] ?? null) === '--check') {
        exit(initialized($path) ? 0 : 2);
    }
    if (!is_file($path)) {
        throw new RuntimeException('Migrated database required.');
    }
    $locks = [];
    foreach (['poll', 'worker', 'whatsapp-poll'] as $role) {
        $locks[] = ProcessLock::acquire(dirname($path) . '/runtime', $role);
    }
    $store = new SqliteStore($path);
    $db = new PDO('sqlite:' . $path);
    try {
        $cutoff = (int) $db->query("SELECT value FROM runtime_state WHERE name='reset_cutoff'")->fetchColumn();
        if ($cutoff === 0 && array_sum($store->inspectBusinessData()) === 0
            && $store->offset() === 0 && $store->channelCursor('whatsapp') === 0) {
            // A fresh test installation must not replay messages from yesterday's session.
            $config = Broadcast\Infrastructure\Runtime\Config::fromEnvironment();
            $cutoff = Broadcast\Local\TelegramTime::cutoff(new Broadcast\Infrastructure\Http\CurlHttpClient(), $config->telegramToken);
            $store->resetBusinessData($cutoff);
        }
        $db->exec("INSERT OR IGNORE INTO runtime_state(name,value) VALUES ('whatsapp_test_initialized',1)");
    } finally {
        $store->close();
    }
    echo "whatsapp_test_initialized\n";
} catch (Throwable $failure) {
    echo 'whatsapp_test_init_failed; exception=' . $failure::class . PHP_EOL;
    exit(1);
}
