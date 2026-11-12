<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Contacts;

use Broadcast\Application\ContactDirectory;
use Broadcast\Domain\DirectoryContact;
use Broadcast\Domain\RecipientSnapshot;
use Broadcast\Domain\ApiFailure;
use PDO;
use RuntimeException;

final class MySqlContactDirectory implements ContactDirectory
{
    public function __construct(private PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new RuntimeException('MySQL is required for the contact directory.'); }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function migrate(): void
    {
        try {
            $sql = file_get_contents(dirname(__DIR__, 3) . '/migrations/mysql/001_contact_directory.sql');
            if ($sql === false) { throw new RuntimeException(); }
            $this->db->exec($sql);
        } catch (\Throwable) { throw new RuntimeException('Contact directory migration failed.'); }
    }

    public function applyOperation(string $operationId, array $change): void
    {
        $this->transaction(function () use ($operationId, $change): void {
            self::identifier($operationId);
            $hash = hash('sha256', json_encode($change, JSON_THROW_ON_ERROR));
            $existing = $this->one('SELECT payload_hash FROM bc_directory_operations WHERE operation_id=? FOR UPDATE', [$operationId]);
            if ($existing !== null) {
                if (!hash_equals($existing['payload_hash'], $hash)) { throw new RuntimeException(); }
                return;
            }
            match ($change['type'] ?? null) {
                'import' => $this->import($change['rows'] ?? []),
                'telegram' => $this->telegram($change),
                'subscription' => $this->subscription($change),
                default => throw new RuntimeException(),
            };
            $this->run('INSERT INTO bc_directory_operations(operation_id,payload_hash) VALUES (?,?)', [$operationId, $hash]);
        });
    }

    private function import(array $rows): void
    {
        if ($rows === [] || count($rows) > 100_000) { throw new RuntimeException(); }
        $seen = [];
        foreach ($rows as $row) {
            $phone = DirectoryContact::phone($row['phone'] ?? null);
            $language = DirectoryContact::language($row['language'] ?? null);
            $email = DirectoryContact::email($row['email'] ?? null);
            if (isset($seen[$phone])) { throw new RuntimeException(); }
            $seen[$phone] = true;
            $existing = $this->one('SELECT id FROM bc_contacts WHERE phone=? FOR UPDATE', [$phone]);
            if ($existing === null) {
                $this->run('INSERT INTO bc_contacts(phone,whatsapp_language,manual_email,whatsapp_enabled,whatsapp_registered) VALUES (?,?,?,1,1)', [$phone, $language, $email]);
            } else {
                // An import never overrides explicit channel switches or resurrects a stopped contact.
                $this->run('UPDATE bc_contacts SET whatsapp_language=?,manual_email=COALESCE(?,manual_email),
                    whatsapp_enabled=CASE WHEN whatsapp_registered=0 THEN 1 ELSE whatsapp_enabled END,whatsapp_registered=1 WHERE id=?', [$language, $email, $existing['id']]);
            }
        }
    }

    private function telegram(array $change): void
    {
        $phone = DirectoryContact::phone($change['phone'] ?? null);
        $language = DirectoryContact::language($change['language'] ?? null);
        $email = DirectoryContact::email($change['email'] ?? null);
        $id = $change['user_id'] ?? null;
        $revision = $change['revision'] ?? null;
        $status = $change['status'] ?? null;
        if (!is_int($id) || $id < 1 || !is_int($revision) || $revision < 0
            || !is_bool($change['subscribed'] ?? null)
            || !in_array($status, ['approved','pending','rejected','changes','draft','unsubscribed'], true)) { throw new RuntimeException(); }
        $byTelegram = $this->one('SELECT * FROM bc_contacts WHERE telegram_user_id=? FOR UPDATE', [$id]);
        if ($byTelegram !== null && (int) $byTelegram['telegram_revision'] >= $revision) { return; }
        $contact = $this->one('SELECT * FROM bc_contacts WHERE phone=? FOR UPDATE', [$phone]);
        if ($contact !== null && $contact['telegram_user_id'] !== null && (int) $contact['telegram_user_id'] !== $id) { throw new RuntimeException(); }
        if ($byTelegram !== null && $contact !== null && $byTelegram['id'] !== $contact['id']) { throw new RuntimeException(); }
        $contact ??= $byTelegram;
        if ($contact === null) {
            $this->run('INSERT INTO bc_contacts(phone,whatsapp_language,telegram_user_id) VALUES (?,?,?)', [$phone, $language, $id]);
            $contact = $this->one('SELECT * FROM bc_contacts WHERE id=? FOR UPDATE', [(int) $this->db->lastInsertId()]);
        }
        if ((int) $contact['telegram_revision'] >= $revision) { return; }
        $approved = $status === 'approved';
        $globalChange = ($status === 'unsubscribed' || ($approved && ($change['reactivate'] ?? false) === true))
            && $revision > (int) $contact['subscription_revision'];
        $this->run('UPDATE bc_contacts SET phone=?,telegram_user_id=?,telegram_status=?,telegram_revision=?,telegram_enabled=?,
            telegram_email=?,telegram_language=?,active=?,subscription_revision=? WHERE id=?', [
            $phone, $id, $status, $revision, (int) ($approved && $change['subscribed']),
            $approved ? $email : $contact['telegram_email'], $approved ? $language : $contact['telegram_language'],
            $globalChange ? (int) $approved : (int) $contact['active'],
            $globalChange ? $revision : (int) $contact['subscription_revision'], $contact['id'],
        ]);
    }

    private function subscription(array $change): void
    {
        $revision = $change['revision'] ?? null;
        if (!is_int($revision) || $revision < 0 || !is_bool($change['active'] ?? null)) { throw new RuntimeException(); }
        if (isset($change['contact_id']) && is_int($change['contact_id']) && $change['contact_id'] > 0) {
            $contact = $this->one('SELECT * FROM bc_contacts WHERE id=? FOR UPDATE', [$change['contact_id']]);
        } elseif (isset($change['phone'])) {
            $phone = DirectoryContact::phone($change['phone']);
            $contact = $this->one('SELECT * FROM bc_contacts WHERE phone=? FOR UPDATE', [$phone]);
            if ($contact === null) {
                // Persist a tombstone even when unsubscribe overtakes initial profile synchronization.
                $this->run('INSERT INTO bc_contacts(phone,active,subscription_revision) VALUES (?,?,?)', [$phone, (int) $change['active'], $revision]);
                return;
            }
        } elseif (isset($change['user_id']) && is_int($change['user_id']) && $change['user_id'] > 0) {
            $contact = $this->one('SELECT * FROM bc_contacts WHERE telegram_user_id=? FOR UPDATE', [$change['user_id']]);
        } else { throw new RuntimeException(); }
        if ($contact === null) { throw new RuntimeException(); }
        if ($revision <= (int) $contact['subscription_revision']) { return; }
        $this->run('UPDATE bc_contacts SET active=?,subscription_revision=? WHERE id=?', [(int) $change['active'], $revision, $contact['id']]);
    }

    public function snapshot(string $snapshotId): RecipientSnapshot
    {
        return $this->transaction(function () use ($snapshotId): RecipientSnapshot {
            self::identifier($snapshotId);
            $snapshot = $this->one('SELECT created_at FROM bc_snapshots WHERE snapshot_id=? FOR UPDATE', [$snapshotId]);
            if ($snapshot === null) {
                $created = time();
                $this->run('INSERT INTO bc_snapshots(snapshot_id,created_at) VALUES (?,?)', [$snapshotId, $created]);
                foreach ($this->run('SELECT * FROM bc_contacts ORDER BY id')->fetchAll() as $row) {
                    foreach ($this->recipients($row) as $recipient) {
                        $this->run('INSERT INTO bc_snapshot_recipients(snapshot_id,contact_id,telegram_user_id,phone,channel,address,language) VALUES (?,?,?,?,?,?,?)', [
                            $snapshotId, $recipient['contact_id'], $recipient['telegram_user_id'], $recipient['phone'], $recipient['channel'], $recipient['address'], $recipient['language'],
                        ]);
                    }
                }
            } else { $created = (int) $snapshot['created_at']; }
            $rows = $this->run('SELECT contact_id,telegram_user_id,phone,channel,address,language FROM bc_snapshot_recipients WHERE snapshot_id=? ORDER BY contact_id,channel', [$snapshotId])->fetchAll();
            foreach ($rows as &$row) {
                $row['contact_id'] = (int) $row['contact_id'];
                $row['telegram_user_id'] = $row['telegram_user_id'] === null ? null : (int) $row['telegram_user_id'];
            }
            return new RecipientSnapshot($snapshotId, $created, $rows);
        });
    }

    private function recipients(array $row): array
    {
        foreach (['active','whatsapp_enabled','telegram_enabled','email_enabled'] as $flag) {
            if (!in_array((int) $row[$flag], [0,1], true)) { throw new RuntimeException(); }
        }
        $phone = DirectoryContact::phone($row['phone']);
        $waLanguage = DirectoryContact::language($row['whatsapp_language']);
        $manualEmail = DirectoryContact::email($row['manual_email']);
        $tgEmail = DirectoryContact::email($row['telegram_email']);
        $tgLanguage = $row['telegram_language'] === null ? null : DirectoryContact::language($row['telegram_language']);
        if (!(bool) $row['active']) { return []; }
        $base = ['contact_id' => (int) $row['id'], 'telegram_user_id' => $row['telegram_user_id'] === null ? null : (int) $row['telegram_user_id'], 'phone' => $phone];
        $rows = [];
        if ((bool) $row['whatsapp_enabled']) { $rows[] = $base + ['channel' => 'whatsapp', 'address' => substr($phone, 1), 'language' => $waLanguage]; }
        if ((bool) $row['telegram_enabled'] && $row['telegram_status'] === 'approved' && $row['telegram_user_id'] !== null) {
            if ($tgLanguage === null) { throw new RuntimeException(); }
            $rows[] = $base + ['channel' => 'telegram', 'address' => (string) $row['telegram_user_id'], 'language' => $tgLanguage];
        }
        $email = $tgEmail ?? $manualEmail;
        if ((bool) $row['email_enabled'] && $email !== null) {
            $rows[] = $base + ['channel' => 'email', 'address' => $email, 'language' => $tgLanguage ?? $waLanguage];
        }
        return $rows;
    }

    public function deliveryAllowed(int $contactId, string $channel): bool
    {
        try {
            if ($contactId < 1 || !in_array($channel, ['telegram','whatsapp','email'], true)) { return false; }
            $row = $this->one('SELECT * FROM bc_contacts WHERE id=?', [$contactId]);
            if ($row === null) { return false; }
            foreach ($this->recipients($row) as $recipient) { if ($recipient['channel'] === $channel) { return true; } }
            return false;
        } catch (\Throwable $failure) { throw new ApiFailure('Contact directory operation failed.', $failure instanceof \PDOException); }
    }

    private function transaction(callable $operation): mixed
    {
        try {
            $this->db->beginTransaction();
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (\Throwable $failure) {
            try { if ($this->db->inTransaction()) { $this->db->rollBack(); } } catch (\Throwable) {}
            throw new ApiFailure('Contact directory operation failed.', $failure instanceof \PDOException);
        }
    }

    private static function identifier(string $id): void
    {
        if (!preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $id)) { throw new RuntimeException(); }
    }

    private function run(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    private function one(string $sql, array $parameters): ?array
    {
        return $this->run($sql, $parameters)->fetch() ?: null;
    }
}
