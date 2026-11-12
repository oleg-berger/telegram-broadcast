<?php
declare(strict_types=1);

use Broadcast\Infrastructure\Files\LocalAttachmentStorage;
use Broadcast\Infrastructure\Files\SqliteAttachmentLinks;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException('Maintenance failed.'); });

try {
    $action = $argv[1] ?? '';
    if ($action === 'allowlist') {
        $db = new PDO(getenv('CONTACTS_MYSQL_DSN'), getenv('CONTACTS_MYSQL_USER'), getenv('CONTACTS_MYSQL_PASSWORD'));
        $phones = $db->query('SELECT phone FROM bc_contacts WHERE active=1 AND whatsapp_enabled=1 AND whatsapp_registered=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $allowed = array_map(static fn (string $phone): string => ltrim($phone, '+'), $phones);
        foreach ($allowed as $phone) {
            if (!preg_match('/^[1-9][0-9]{6,14}$/D', $phone)) { throw new RuntimeException(); }
        }
        // The caller captures this private output and writes it to a protected file.
        echo json_encode($allowed, JSON_THROW_ON_ERROR);
    } elseif ($action === 'probe') {
        $storage = new LocalAttachmentStorage(getenv('ATTACHMENTS_PATH'));
        $source = $storage->downloadPath();
        try {
            file_put_contents($source, random_bytes(64));
            $file = $storage->put($source, 'installation-check.bin');
        } finally {
            if (is_file($source)) { unlink($source); }
        }
        $links = new SqliteAttachmentLinks(getenv('DATABASE_PATH'), $storage, getenv('ATTACHMENTS_PUBLIC_BASE_URL'), getenv('ATTACHMENTS_LINK_KEY_FILE') ?: null);
        echo json_encode(['url' => $links->issue($file, 1, time()), 'sha256' => $file->sha256, 'key' => $file->storageKey], JSON_THROW_ON_ERROR);
    } elseif ($action === 'cleanup-probe') {
        $key = $argv[2] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) { throw new RuntimeException(); }
        $db = new PDO('sqlite:' . getenv('DATABASE_PATH'));
        $db->exec('PRAGMA busy_timeout=5000');
        $db->beginTransaction();
        foreach (['attachment_links', 'attachment_link_files'] as $table) {
            $query = $db->prepare('DELETE FROM ' . $table . ' WHERE storage_key=?');
            $query->execute([$key]);
        }
        $db->commit();
        (new LocalAttachmentStorage(getenv('ATTACHMENTS_PATH')))->delete($key);
        echo "probe_removed\n";
    } else {
        throw new RuntimeException();
    }
} catch (Throwable) {
    echo "maintenance_failed\n";
    exit(1);
}
