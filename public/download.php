<?php
declare(strict_types=1);

use Broadcast\Infrastructure\Files\AttachmentDownload;
use Broadcast\Infrastructure\Files\LocalAttachmentStorage;
use Broadcast\Infrastructure\Files\SqliteAttachmentLinks;

ini_set('display_errors','0');
ini_set('log_errors','0');
set_error_handler(static function (): never { throw new ErrorException('Download operation failed.'); });

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $database = getenv('DATABASE_PATH');
    $storagePath = getenv('ATTACHMENTS_PATH');
    $baseUrl = getenv('ATTACHMENTS_PUBLIC_BASE_URL');
    if (!$database || !$storagePath || !$baseUrl || !is_file($database)) { throw new RuntimeException(); }
    $storage = new LocalAttachmentStorage($storagePath);
    $links = new SqliteAttachmentLinks($database,$storage,$baseUrl);
    (new AttachmentDownload($links,$storage))->serve($_SERVER['REQUEST_METHOD'] ?? '',is_string($_GET['token'] ?? null) ? $_GET['token'] : '',time(),
        static function (int $status,array $headers): void {
            http_response_code($status);
            foreach ($headers as $name=>$value) { header($name.': '.$value); }
        },static function (string $chunk): void { echo $chunk; });
} catch (Throwable) {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Length: 0');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
}
