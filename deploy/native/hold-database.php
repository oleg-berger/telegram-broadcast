<?php
declare(strict_types=1);

// Keep WAL/SHM present without granting the HTTP process write access to SQLite.
umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException(); });
try {
    $path = getenv('DATABASE_PATH');
    if (!$path || !is_file($path) || is_link($path)) { throw new RuntimeException(); }
    if (($argv[1] ?? '') === '--wait') {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            clearstatcache();
            if (is_file($path . '-wal') && is_file($path . '-shm')) { exit(0); }
            usleep(100_000);
        }
        exit(1);
    }
    $db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=5000');
    if ($db->query('PRAGMA journal_mode=WAL')->fetchColumn() !== 'wal') { throw new RuntimeException(); }
    while (true) {
        $query = $db->query('SELECT COUNT(*) FROM sqlite_master');
        $query->fetchColumn();
        $query->closeCursor();
        sleep(2);
    }
} catch (Throwable) {
    exit(1);
}
