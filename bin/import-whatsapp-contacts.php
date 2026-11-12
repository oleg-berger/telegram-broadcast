#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Broadcast\Maintenance;

use Broadcast\Infrastructure\Contacts\MySqlContactDirectory;
use Broadcast\Infrastructure\Import\XlsxContacts;
use PDO;
use RuntimeException;

final class ContactImportCommand
{
    /** @return array{exit:int,report:array} */
    public function run(array $arguments): array
    {
        try {
            $file = array_shift($arguments);
            if (!is_string($file) || $file === '') { throw new RuntimeException(); }
            $apply = false;
            $expectedHash = null;
            $skip = [];
            $continuation = [];
            foreach ($arguments as $argument) {
                if ($argument === '--apply') { $apply = true; }
                elseif (preg_match('/^--confirm-sha256=([a-f0-9]{64})$/D', $argument, $match)) { $expectedHash = $match[1]; }
                elseif (preg_match('/^--skip-row=([1-9][0-9]*)$/D', $argument, $match)) { $skip[] = (int) $match[1]; }
                elseif (preg_match('/^--continuation-row=([1-9][0-9]*)$/D', $argument, $match)) { $continuation[] = (int) $match[1]; }
                else { throw new RuntimeException(); }
            }
            if (array_intersect($skip, $continuation) !== [] || ($apply && $expectedHash === null)) { throw new RuntimeException(); }
            $result = (new XlsxContacts())->read($file, $skip, $continuation);
            $languages = [];
            foreach ($result['rows'] as $row) { $languages[$row['language']] = ($languages[$row['language']] ?? 0) + 1; }
            $report = ['status' => 'dry_run', 'contacts' => count($result['rows']), 'source_rows' => $result['total'],
                'languages' => $languages, 'with_email' => count(array_filter($result['rows'], static fn ($r) => $r['email'] !== null)),
                'errors' => $result['errors'], 'warnings' => $result['warnings'], 'sha256' => $result['sha256']];
            if ($result['errors'] !== [] || $result['rows'] === []) { $report['status'] = 'validation_failed'; return ['exit' => 1, 'report' => $report]; }
            if ($apply) {
                if (!hash_equals($expectedHash, $result['sha256'])) { throw new RuntimeException(); }
                $dsn = getenv('CONTACTS_MYSQL_DSN');
                if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) { throw new RuntimeException(); }
                $db = new PDO($dsn, getenv('CONTACTS_MYSQL_USER') ?: '', getenv('CONTACTS_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $rows = array_map(static fn ($r) => ['phone' => $r['phone'], 'language' => $r['language'], 'email' => $r['email']], $result['rows']);
                $operation = 'xlsx:' . hash('sha256', json_encode([$result['sha256'], $skip, $continuation], JSON_THROW_ON_ERROR));
                (new MySqlContactDirectory($db))->applyOperation($operation, ['type' => 'import', 'rows' => $rows]);
                $report['status'] = 'imported';
            }
            return ['exit' => 0, 'report' => $report];
        } catch (\Throwable) {
            return ['exit' => 1, 'report' => ['status' => 'import_failed']];
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require dirname(__DIR__) . '/vendor/autoload.php';
    ini_set('display_errors', '0');
    set_error_handler(static function (): never { throw new RuntimeException('Import operation failed.'); });
    $result = (new ContactImportCommand())->run(array_slice($argv, 1));
    echo json_encode($result['report'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['exit']);
}
