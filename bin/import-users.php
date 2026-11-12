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
    private const LEGACY_HEADERS = ['Telegram ID', 'Имя', 'Страна', 'Организация', 'Телефон', 'Email', 'Язык', 'Статус', 'Дата подачи (UTC)', 'Дата одобрения (UTC)'];
    private const HEADERS = ['ID пользователя', 'Имя', 'Страна', 'Организация', 'Телефон', 'Email', 'Язык', 'Статус', 'Дата подачи (UTC)', 'Дата одобрения (UTC)', 'Telegram ID', 'WhatsApp ID', 'Подтверждённый номер WhatsApp'];

    public function read(string $file): array
    {
        $reader = new Reader();
        $reader->open($file);
        $users = [];
        $seen = ['id' => [], 'phone' => [], 'email' => [], 'identity' => []];
        try {
            $sheets = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                if (++$sheets > 1) {
                    throw new RuntimeException('Expected one worksheet.');
                }
                $first = true;
                $legacy = false;
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    foreach ($values as $value) {
                        if (!is_string($value) && $value !== null) {
                            throw new RuntimeException('Expected text cells from the bot export.');
                        }
                    }
                    $values = array_map(static fn ($value) => trim($value ?? ''), $values);
                    if ($first) {
                        $legacy = $values === self::LEGACY_HEADERS;
                        if (!$legacy && $values !== self::HEADERS) {
                            throw new RuntimeException('Unexpected export columns.');
                        }
                        $first = false;
                        continue;
                    }
                    if (!array_filter($values, static fn ($v) => $v !== '')) {
                        continue;
                    }
                    if (count($values) !== ($legacy ? 10 : 13)) {
                        throw new RuntimeException('Unexpected row width.');
                    }
                    [$id, $name, $country, $company, $phone, $email, $language, $status, $submitted, $approved] = array_slice($values, 0, 10);
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
                    $identities = $this->identities($legacy ? (string) $number : $values[10], $legacy ? '' : $values[11], $legacy ? '' : $values[12]);
                    foreach (['id', 'phone', 'email'] as $field) {
                        $key = $field === 'email' ? strtolower($user[$field]) : $user[$field];
                        if (isset($seen[$field][$key])) {
                            throw new RuntimeException('Duplicate export identity.');
                        }
                        $seen[$field][$key] = true;
                    }
                    foreach ($identities as $identity) {
                        $key = $identity['channel'] . ':' . $identity['address'];
                        if (isset($seen['identity'][$key])) {
                            throw new RuntimeException('Duplicate export channel identity.');
                        }
                        $seen['identity'][$key] = true;
                    }
                    $users[] = ['profile' => $user, 'identities' => $identities, 'legacy' => $legacy];
                }
            }
        } finally {
            $reader->close();
            // OpenSpout row callbacks form cycles; release XML handles on early rejection on Windows.
            unset($sheet, $reader);
            gc_collect_cycles();
        }
        if ($users === []) {
            throw new RuntimeException('Export is empty.');
        }
        return $users;
    }

    /** @return list<array{channel:string,address:string,verified_phone:?string}> */
    private function identities(string $telegram, string $whatsapp, string $verified): array
    {
        $identities = [];
        if ($telegram !== '') {
            $number = filter_var($telegram, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($number === false || (string) $number !== $telegram) {
                throw new RuntimeException('Invalid exported channel identity.');
            }
            $identities[] = ['channel' => 'telegram', 'address' => $telegram, 'verified_phone' => null];
        }
        if ($whatsapp !== '') {
            $phone = (bool) preg_match('/^[1-9][0-9]{6,14}$/D', $whatsapp);
            $lid = (bool) preg_match('/^[1-9][0-9]{0,19}@lid$/D', $whatsapp);
            if ((!$phone && !$lid) || ($phone && $verified !== '+' . $whatsapp) || ($lid && $verified !== '')) {
                throw new RuntimeException('Invalid exported channel identity.');
            }
            $identities[] = ['channel' => 'whatsapp', 'address' => $whatsapp, 'verified_phone' => $verified === '' ? null : $verified];
        } elseif ($verified !== '') {
            throw new RuntimeException('Invalid exported channel identity.');
        }
        if ($identities === []) {
            throw new RuntimeException('An explicit channel identity is required.');
        }
        return $identities;
    }

    public function run(string $database, string $file, bool $confirm = false): array
    {
        $path = realpath($database);
        if ($path === false || !is_file($path) || is_link($database)) {
            throw new RuntimeException('An existing migrated database is required.');
        }
        $records = $this->read($file);
        $locks = [];
        foreach (['poll', 'worker', 'whatsapp-poll', 'backup'] as $role) {
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
            // Require the current schema; maintenance never applies migrations implicitly.
            $db->query('SELECT id,revision,edit_field,approved_at FROM users LIMIT 0');
            $db->query('SELECT user_id,phone,email FROM contacts LIMIT 0');
            $db->query('SELECT id,user_id,channel,address,verified_phone,enabled,retired FROM channel_identities LIMIT 0');
            $db->query('SELECT channel,address,identity_id FROM channel_identity_aliases LIMIT 0');
            $db->query('SELECT field,value,user_id FROM contact_reservations LIMIT 0');
            $sequence = $db->querySingle("SELECT value FROM runtime_state WHERE name='person_sequence'");
            if (filter_var($sequence, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                throw new RuntimeException('A migrated identity sequence is required.');
            }
            $beforeJobs = (int) $db->querySingle('SELECT COUNT(*) FROM jobs');
            $backup = null;
            if ($confirm) {
                $dir = dirname($path) . '/backups/before-import/import-' . bin2hex(random_bytes(8));
                $backup = (new SqliteBackup())->create($path, $dir);
            }
            $db->exec($confirm ? 'BEGIN IMMEDIATE' : 'BEGIN');
            $transaction = true;
            $new = [];
            $skipped = 0;
            $largestId = max((int) $sequence, (int) $db->querySingle('SELECT COALESCE(MAX(id),0) FROM users'));
            foreach ($records as $record) {
                $user = $record['profile'];
                $largestId = max($largestId, $user['id']);
                $old = $this->query($db, 'SELECT * FROM users WHERE id=?', [$user['id']])->fetchArray(SQLITE3_ASSOC);
                $contacts = $this->rows($db, 'SELECT * FROM contacts WHERE user_id=? OR phone=? OR email=? COLLATE NOCASE', [$user['id'], $user['phone'], $user['email']]);
                $reservations = $this->rows($db, "SELECT field,value,user_id FROM contact_reservations WHERE (field='phone' AND value=?) OR (field='email' AND value=? COLLATE NOCASE)", [$user['phone'], $user['email']]);
                foreach ($reservations as $reservation) {
                    if ($reservation['user_id'] !== $user['id']) {
                        throw new RuntimeException('Reserved contact conflict.');
                    }
                }
                $this->checkIdentities($db, $user['id'], $record['identities'], $old !== false, $record['legacy']);
                if ($old !== false) {
                    foreach ($user as $field => $value) {
                        if ($old[$field] !== $value) {
                            throw new RuntimeException('Existing profile conflict.');
                        }
                    }
                    if (count($contacts) !== 1 || $contacts[0]['user_id'] !== $user['id']
                        || $contacts[0]['phone'] !== $user['phone'] || $contacts[0]['email'] !== $user['email']
                        || count($reservations) !== 2) {
                        throw new RuntimeException('Existing contacts conflict.');
                    }
                    ++$skipped;
                    continue;
                }
                if ($contacts !== [] || $reservations !== []) {
                    throw new RuntimeException('Reserved contact conflict.');
                }
                $new[] = $record;
            }
            $revision = max(time(), (int) $db->querySingle('SELECT COALESCE(MAX(revision),0) FROM users'),
                (int) $db->querySingle("SELECT value FROM runtime_state WHERE name='registration_revision_floor'")) + 1;
            if ($confirm) {
                foreach ($new as $record) {
                    $user = $record['profile'];
                    $this->query($db, "INSERT INTO users(id,name,country,company,phone,email,language,status,submitted_at,approved_at,step,subscribed,completed,choosing_language,revision,edit_field) VALUES (?,?,?,?,?,?,?,?,?,?,'review',1,1,0,?,'')", [...array_values($user), $revision]);
                    $this->query($db, 'INSERT INTO contacts(user_id,phone,email) VALUES (?,?,?)', [$user['id'], $user['phone'], $user['email']]);
                    foreach (['phone', 'email'] as $field) {
                        $this->query($db, 'INSERT INTO contact_reservations(field,value,user_id) VALUES (?,?,?)', [$field, $user[$field], $user['id']]);
                    }
                    foreach ($record['identities'] as $identity) {
                        $this->query($db, 'INSERT INTO channel_identities(user_id,channel,address,verified_phone,enabled) VALUES (?,?,?,?,1)', [$user['id'], $identity['channel'], $identity['address'], $identity['verified_phone']]);
                    }
                }
                $this->query($db, "UPDATE runtime_state SET value=MAX(CAST(value AS INTEGER),?) WHERE name='person_sequence'", [$largestId]);
            }
            if ((int) $db->querySingle('SELECT COUNT(*) FROM jobs') !== $beforeJobs || $db->query('PRAGMA foreign_key_check')->fetchArray() !== false) {
                throw new RuntimeException('Import verification failed.');
            }
            $db->exec('COMMIT');
            $transaction = false;
            return ['event' => $confirm ? 'users_imported' : 'users_import_checked', 'records' => count($records),
                'inserted' => $confirm ? count($new) : 0, 'would_insert' => count($new), 'unchanged' => $skipped,
                'jobs_created' => 0, 'backup' => $backup];
        } finally {
            if ($transaction) {
                $db->exec('ROLLBACK');
            }
            $db->close();
        }
    }

    private function checkIdentities(SQLite3 $db, int $userId, array $identities, bool $exists, bool $legacy): void
    {
        foreach ($identities as $identity) {
            $owners = $this->rows($db, 'SELECT i.* FROM channel_identities i WHERE i.channel=? AND (i.address=? OR EXISTS (SELECT 1 FROM channel_identity_aliases a WHERE a.identity_id=i.id AND a.channel=? AND a.address=?))',
                [$identity['channel'], $identity['address'], $identity['channel'], $identity['address']]);
            if ($exists) {
                if (count($owners) !== 1 || $owners[0]['user_id'] !== $userId || $owners[0]['retired'] !== 0
                    || $owners[0]['address'] !== $identity['address'] || $owners[0]['verified_phone'] !== $identity['verified_phone']) {
                    throw new RuntimeException('Existing channel identity conflict.');
                }
            } elseif ($owners !== []) {
                throw new RuntimeException('Reserved channel identity conflict.');
            }
        }
        // Legacy exports describe only Telegram; preserve any later explicitly linked channel.
        if ($exists && !$legacy) {
            $current = $this->rows($db, 'SELECT id FROM channel_identities WHERE user_id=? AND retired=0', [$userId]);
            if (count($current) !== count($identities)) {
                throw new RuntimeException('Existing channel identity conflict.');
            }
        }
    }

    private function rows(SQLite3 $db, string $sql, array $values): array
    {
        $result = $this->query($db, $sql, $values);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function query(SQLite3 $db, string $sql, array $values): \SQLite3Result
    {
        $statement = $db->prepare($sql);
        foreach ($values as $index => $value) {
            $statement->bindValue($index + 1, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
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
