#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Broadcast\Maintenance;

use Broadcast\Domain\PhoneNumber;
use Broadcast\Infrastructure\Runtime\ProcessLock;
use Broadcast\Infrastructure\Runtime\SqliteBackup;
use DateTimeImmutable;
use DateTimeZone;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use SQLite3;

/** One-off offline recovery from an operator-approved export; never creates jobs. */
final class UserImport
{
    private const HEADERS = ['Telegram ID', 'Имя', 'Страна', 'Организация', 'Телефон', 'Email', 'Язык', 'Статус', 'Дата подачи (UTC)', 'Дата одобрения (UTC)'];

    public function read(string $file): array
    {
        $reader = new Reader();
        $reader->open($file);
        $users = [];
        $seen = ['id' => [], 'phone' => [], 'email' => []];
        try {
            $sheets = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                if (++$sheets > 1) {
                    throw new RuntimeException('Expected one worksheet.');
                }
                $first = true;
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    foreach ($values as $value) {
                        if (!is_string($value) && $value !== null) {
                            throw new RuntimeException('Expected text cells from the bot export.');
                        }
                    }
                    $values = array_map(static fn ($value) => trim($value ?? ''), $values);
                    if ($first) {
                        if ($values !== self::HEADERS) {
                            throw new RuntimeException('Unexpected export columns.');
                        }
                        $first = false;
                        continue;
                    }
                    if (!array_filter($values, static fn ($v) => $v !== '')) {
                        continue;
                    }
                    if (count($values) !== 10) {
                        throw new RuntimeException('Unexpected row width.');
                    }
                    [$id, $name, $country, $company, $phone, $email, $language, $status, $submitted, $approved] = $values;
                    $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $phone = PhoneNumber::normalize($phone);
                    if ($number === false || $name === '' || $country === '' || $company === '' || $phone === null
                        || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($language, ['RU', 'EN-GB', 'ES', 'FR'], true)
                        || $status !== 'approved') {
                        throw new RuntimeException('Invalid approved profile.');
                    }
                    $user = ['id' => $number, 'name' => $name, 'country' => $country, 'company' => $company,
                        'phone' => $phone, 'email' => $email, 'language' => $language, 'status' => $status,
                        'submitted_at' => $this->date($submitted), 'approved_at' => $this->date($approved)];
                    if ($user['approved_at'] < $user['submitted_at']) {
                        throw new RuntimeException('Invalid approval dates.');
                    }
                    foreach (['id', 'phone', 'email'] as $field) {
                        $key = $field === 'email' ? strtolower($user[$field]) : $user[$field];
                        if (isset($seen[$field][$key])) {
                            throw new RuntimeException('Duplicate export identity.');
                        }
                        $seen[$field][$key] = true;
                    }
                    $users[] = $user;
                }
            }
        } finally {
            $reader->close();
        }
        if ($users === []) {
            throw new RuntimeException('Export is empty.');
        }
        return $users;
    }

    public function run(string $database, string $file, bool $confirm = false): array
    {
        $path = realpath($database);
        if ($path === false || !is_file($path) || is_link($database)) {
            throw new RuntimeException('An existing migrated database is required.');
        }
        $users = $this->read($file);
        $locks = [];
        foreach (['poll', 'worker', 'backup'] as $role) {
            $locks[] = ProcessLock::acquire(dirname($path) . '/runtime', $role);
        }
        $db = new SQLite3($path, $confirm ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys=ON');
        $transaction = false;
        try {
            if ($db->querySingle('PRAGMA integrity_check') !== 'ok' || $db->query('PRAGMA foreign_key_check')->fetchArray() !== false) {
                throw new RuntimeException('Database integrity check failed.');
            }
            // Queries verify that the current schema exists, without applying migrations.
            $db->query('SELECT id,revision,edit_field,approved_at FROM users LIMIT 0');
            $db->query('SELECT user_id,phone,email FROM contacts LIMIT 0');
            $db->query('SELECT name,value FROM runtime_state LIMIT 0');
            $beforeJobs = (int) $db->querySingle('SELECT COUNT(*) FROM jobs');
            $backup = null;
            if ($confirm) {
                // Hold process locks before creating the rollback snapshot.
                $dir = dirname($path) . '/backups/before-import/import-' . bin2hex(random_bytes(8));
                $backup = (new SqliteBackup())->create($path, $dir);
            }
            $db->exec($confirm ? 'BEGIN IMMEDIATE' : 'BEGIN');
            $transaction = true;
            $new = [];
            $skipped = 0;
            foreach ($users as $user) {
                $old = $this->query($db, 'SELECT * FROM users WHERE id=?', [$user['id']])->fetchArray(SQLITE3_ASSOC);
                $contacts = $this->query($db, 'SELECT * FROM contacts WHERE user_id=? OR phone=? OR email=? COLLATE NOCASE', [$user['id'], $user['phone'], $user['email']]);
                $matches = [];
                while ($row = $contacts->fetchArray(SQLITE3_ASSOC)) {
                    $matches[] = $row;
                }
                if ($old !== false) {
                    foreach ($user as $field => $value) {
                        if ($old[$field] !== $value) {
                            throw new RuntimeException('Existing profile conflict.');
                        }
                    }
                    if (count($matches) !== 1 || $matches[0]['user_id'] !== $user['id']
                        || $matches[0]['phone'] !== $user['phone'] || $matches[0]['email'] !== $user['email']) {
                        throw new RuntimeException('Existing contacts conflict.');
                    }
                    ++$skipped;
                    continue;
                }
                if ($matches !== []) {
                    throw new RuntimeException('Reserved contact conflict.');
                }
                $new[] = $user;
            }
            $revision = max(time(), (int) $db->querySingle('SELECT COALESCE(MAX(revision),0) FROM users'),
                (int) $db->querySingle("SELECT value FROM runtime_state WHERE name='registration_revision_floor'")) + 1;
            if ($confirm) {
                foreach ($new as $user) {
                    $this->query($db, "INSERT INTO users(id,name,country,company,phone,email,language,status,submitted_at,approved_at,step,subscribed,completed,choosing_language,revision,edit_field) VALUES (?,?,?,?,?,?,?,?,?,?,'review',1,1,0,?,'')", [...array_values($user), $revision]);
                    $this->query($db, 'INSERT INTO contacts(user_id,phone,email) VALUES (?,?,?)', [$user['id'], $user['phone'], $user['email']]);
                }
            }
            if ((int) $db->querySingle('SELECT COUNT(*) FROM jobs') !== $beforeJobs || $db->query('PRAGMA foreign_key_check')->fetchArray() !== false) {
                throw new RuntimeException('Import verification failed.');
            }
            $db->exec('COMMIT');
            $transaction = false;
            return ['event' => $confirm ? 'users_imported' : 'users_import_checked', 'records' => count($users),
                'inserted' => $confirm ? count($new) : 0, 'would_insert' => count($new), 'unchanged' => $skipped,
                'jobs_created' => 0, 'backup' => $backup];
        } finally {
            if ($transaction) {
                $db->exec('ROLLBACK');
            }
            $db->close();
        }
    }

    private function query(SQLite3 $db, string $sql, array $values): \SQLite3Result
    {
        $statement = $db->prepare($sql);
        foreach ($values as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        return $statement->execute();
    }

    private function date(string $text): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $text || $date->getTimestamp() < 1) {
            throw new RuntimeException('Invalid export date.');
        }
        return $date->getTimestamp();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077);
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    set_error_handler(static function (): never { throw new RuntimeException('Import operation failed.'); });
    try {
        require dirname(__DIR__) . '/vendor/autoload.php';
        if (count($argv) < 3 || count($argv) > 4 || (isset($argv[3]) && $argv[3] !== '--confirm')) {
            throw new RuntimeException('Usage error.');
        }
        $result = (new UserImport())->run($argv[1], $argv[2], ($argv[3] ?? '') === '--confirm');
        // Only aggregate counts and the rollback path; no profile data or upstream errors.
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (\Throwable $failure) {
        echo json_encode(['event' => 'users_import_failed', 'type' => $failure::class]) . PHP_EOL;
        exit(1);
    }
}
