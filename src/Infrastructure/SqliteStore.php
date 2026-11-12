<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure;

use Broadcast\Application\Store;
use Broadcast\Application\Messages;
use PDO;
use Throwable;

final class SqliteStore implements Store
{
    private const array BUSINESS_TABLES = ['attachment_links', 'attachment_link_files', 'directory_link_audit', 'draft_previews', 'draft_attachments', 'broadcast_attachments', 'attachments', 'directory_local_state', 'directory_operations', 'directory_links', 'whatsapp_parts', 'whatsapp_receipts', 'whatsapp_pending_receipts', 'link_requests', 'channel_menus', 'identity_conflicts', 'channel_identity_aliases', 'channel_identities', 'channel_states', 'jobs', 'translations', 'drafts', 'broadcasts', 'contact_reservations', 'contacts', 'staff_sessions', 'users'];

    private PDO $db;
    private int $transactionDepth = 0;

    public function __construct(string $path, private readonly ?int $teamChannelId = null, private readonly bool $directoryEnabled = false)
    {
        if ($teamChannelId !== null && $teamChannelId >= 0) {
            throw new \InvalidArgumentException('Team channel must use a negative Telegram chat ID.');
        }
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            if (!mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Cannot create database directory.');
            }
        }
        $this->db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
    }

    public function migrate(): void
    {
        $this->transaction(function (): void {
            $this->db->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY)');
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') as $file) {
                $version = basename($file);
                if (!$this->one('SELECT version FROM migrations WHERE version = ?', [$version])) {
                    $this->db->exec(file_get_contents($file));
                    $this->run('INSERT INTO migrations(version) VALUES (?)', [$version]);
                }
            }
        });
    }

    /** Release a maintenance connection even when an exception trace retains this object. */
    public function close(): void
    {
        unset($this->db);
    }

    public function transaction(callable $operation): mixed
    {
        // Acquire the write lock before reading state; polling and sending use separate connections.
        $depth = $this->transactionDepth;
        $this->db->exec($depth === 0 ? 'BEGIN IMMEDIATE' : 'SAVEPOINT nested_' . $depth);
        ++$this->transactionDepth;
        try {
            $result = $operation();
            $this->db->exec($depth === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT nested_' . $depth);
            return $result;
        } catch (Throwable $exception) {
            $this->db->exec($depth === 0 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT nested_' . $depth);
            if ($depth > 0) { $this->db->exec('RELEASE SAVEPOINT nested_' . $depth); }
            throw $exception;
        } finally {
            --$this->transactionDepth;
        }
    }

    public function acceptUpdate(int $id, ?int $sourceDate = null): bool
    {
        if ($this->run('INSERT OR IGNORE INTO processed_updates(id) VALUES (?)', [$id])->rowCount() !== 1) {
            return false;
        }
        $cutoff = $this->one("SELECT value FROM runtime_state WHERE name='reset_cutoff'");
        // Discarded backlog still advances the polling offset in the same transaction.
        return $cutoff === null || ($sourceDate !== null && $sourceDate > (int) $cutoff['value']);
    }

    public function offset(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(id) + 1, 0) FROM processed_updates')->fetchColumn();
    }

    /** Counts only; maintenance must never expose profiles or queued payloads. */
    public function inspectBusinessData(): array
    {
        $expected = [...self::BUSINESS_TABLES, 'channel_events', 'channel_cursors', 'migrations', 'processed_updates', 'runtime_state', 'sqlite_sequence'];
        $actual = $this->db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        sort($expected);
        sort($actual);
        if ($actual !== $expected || $this->db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
            || $this->db->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new \RuntimeException('Database is not suitable for business data reset.');
        }
        $counts = [];
        foreach (self::BUSINESS_TABLES as $table) {
            $counts[$table] = (int) $this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        }
        return $counts;
    }

    /** Operator maintenance only; caller holds poll, whatsapp-poll, worker and backup locks. */
    public function resetBusinessData(int $cutoff): array
    {
        if ($cutoff < 1) {
            throw new \InvalidArgumentException('Reset cutoff must be positive.');
        }
        return $this->transaction(function () use ($cutoff): array {
            $this->inspectBusinessData();
            $previousCutoff = (int) ($this->one("SELECT value FROM runtime_state WHERE name='reset_cutoff'")['value'] ?? 0);
            $previousFloor = (int) ($this->one("SELECT value FROM runtime_state WHERE name='registration_revision_floor'")['value'] ?? 0);
            $revision = (int) $this->db->query('SELECT COALESCE(MAX(revision),0) FROM users')->fetchColumn();
            $floor = max($previousFloor, $revision) + 1;
            $cutoff = max($cutoff, $previousCutoff);
            foreach (self::BUSINESS_TABLES as $table) {
                $this->db->exec("DELETE FROM $table");
            }
            // Keep both transports' event receipts/cursors and all identity/entity sequences.
            $this->run("DELETE FROM runtime_state WHERE name='mail_next_at'");
            foreach (['reset_cutoff' => $cutoff, 'registration_revision_floor' => $floor] as $name => $value) {
                $this->run('INSERT INTO runtime_state(name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value', [$name, $value]);
            }
            if (array_sum($this->inspectBusinessData()) !== 0) {
                throw new \RuntimeException('Business data reset verification failed.');
            }
            return ['cutoff' => $cutoff, 'revision_floor' => $floor, 'next_update' => $this->offset()];
        });
    }

    public function user(int $id): ?array { return $this->one('SELECT * FROM users WHERE id = ?', [$id]); }

    public function saveUser(array $user): void
    {
        $this->transaction(function () use ($user): void {
            $this->persistUser($user);
            // Compatibility boundary: old direct callers create Telegram fixtures/imports by ID.
            // Incoming traffic must use ensureIdentity and never enter this fallback.
            if ($this->identities($user['id']) === [] && ($user['status'] ?? 'draft') !== 'linked') {
                $this->run("INSERT INTO channel_identities(user_id,channel,address,enabled) VALUES (?,'telegram',?,?)", [$user['id'], (string) $user['id'], ($user['status'] ?? 'draft') === 'approved' ? (int) $user['subscribed'] : 1]);
            }
            $this->run("UPDATE runtime_state SET value=MAX(value,CAST(? AS INTEGER)) WHERE name='person_sequence'", [$user['id']]);
            $this->syncDirectoryUser($user['id']);
        });
    }

    private function persistUser(array $user): void
    {
        $columns = ['id', 'language', 'step', 'name', 'company', 'country', 'phone', 'subscribed', 'completed', 'choosing_language', 'email', 'status', 'submitted_at', 'approved_at', 'revision', 'edit_field'];
        $user += ['email' => '', 'status' => 'draft', 'submitted_at' => null, 'approved_at' => null, 'revision' => 0, 'edit_field' => ''];
        if ($this->user($user['id']) === null) {
            $floor = (int) ($this->one("SELECT value FROM runtime_state WHERE name='registration_revision_floor'")['value'] ?? 0);
            $user['revision'] = max($user['revision'], $floor);
        }
        $values = array_map(fn ($key) => $user[$key], $columns);
        $assignments = implode(', ', array_map(fn ($key) => "$key=excluded.$key", array_slice($columns, 1)));
        $this->run('INSERT INTO users (' . implode(',', $columns) . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET ' . $assignments, $values);
    }

    public function identity(string $channel, string $address): ?array
    {
        return $this->one('SELECT i.* FROM channel_identities i WHERE i.channel=? AND i.retired=0 AND (i.address=? OR EXISTS (SELECT 1 FROM channel_identity_aliases a WHERE a.identity_id=i.id AND a.channel=? AND a.address=?))', [$channel, $address, $channel, $address]);
    }

    public function identityById(int $id): ?array { return $this->one('SELECT * FROM channel_identities WHERE id=?', [$id]); }
    public function identities(int $userId): array { return $this->run('SELECT * FROM channel_identities WHERE user_id=? AND retired=0 ORDER BY id', [$userId])->fetchAll(); }

    public function ensureIdentity(string $channel, string $address, ?string $verifiedPhone = null): array
    {
        $this->validateIdentity($channel, $address, $verifiedPhone);
        return $this->transaction(function () use ($channel, $address, $verifiedPhone): array {
            $identity = $this->identity($channel, $address);
            $canonical = $channel === 'whatsapp' && $verifiedPhone !== null ? substr($verifiedPhone, 1) : $address;
            $known = $this->identity($channel, $canonical);
            if ($identity !== null && $known !== null && $identity['id'] !== $known['id']) {
                $this->run('UPDATE channel_identities SET enabled=0,verified_phone=NULL WHERE id=?', [$identity['id']]);
                $this->run('INSERT INTO identity_conflicts(source_identity_id,target_identity_id,target_user_id,verified_phone) VALUES (?,?,?,?) ON CONFLICT(source_identity_id) DO UPDATE SET target_identity_id=excluded.target_identity_id,target_user_id=excluded.target_user_id,verified_phone=excluded.verified_phone', [$identity['id'], $known['id'], $known['user_id'], $verifiedPhone]);
                return $this->identityById($identity['id']) + ['created' => false, 'address_conflict' => true, 'conflict_user_id' => $known['user_id']];
            }
            $identity ??= $known;
            $created = $identity === null;
            if ($created) {
                $sequence = (int) ($this->one("SELECT value FROM runtime_state WHERE name='person_sequence'")['value'] ?? 0);
                $userId = max($sequence, (int) $this->db->query('SELECT COALESCE(MAX(id),0) FROM users')->fetchColumn()) + 1;
                $floor = (int) ($this->one("SELECT value FROM runtime_state WHERE name='registration_revision_floor'")['value'] ?? 0);
                $this->run('INSERT INTO users(id,revision) VALUES (?,?)', [$userId, $floor]);
                $this->run("UPDATE runtime_state SET value=? WHERE name='person_sequence'", [$userId]);
                $this->run('INSERT INTO channel_identities(user_id,channel,address,verified_phone) VALUES (?,?,?,?)', [$userId, $channel, $canonical, $verifiedPhone]);
                $identity = $this->identityById((int) $this->db->lastInsertId());
            } elseif ($verifiedPhone !== null) {
                if ($identity['verified_phone'] !== null && $identity['verified_phone'] !== $verifiedPhone) {
                    throw new \InvalidArgumentException('Conflicting verified phone.');
                }
                if ($identity['address'] !== $canonical) {
                    $this->run('INSERT OR IGNORE INTO channel_identity_aliases(channel,address,identity_id) VALUES (?,?,?)', [$channel, $identity['address'], $identity['id']]);
                }
                $this->run('UPDATE channel_identities SET address=?,verified_phone=? WHERE id=?', [$canonical, $verifiedPhone, $identity['id']]);
                $identity = $this->identityById($identity['id']);
            }
            if ($address !== $canonical) {
                $this->run('INSERT OR IGNORE INTO channel_identity_aliases(channel,address,identity_id) VALUES (?,?,?)', [$channel, $address, $identity['id']]);
            }
            if ($channel === 'telegram' && !$identity['enabled']) {
                $this->run('UPDATE channel_identities SET enabled=1 WHERE id=?', [$identity['id']]);
                $identity['enabled'] = 1;
            }
            return $identity + ['created' => $created];
        });
    }

    private function validateIdentity(string $channel, string $address, ?string $verifiedPhone): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)
            || !preg_match($channel === 'telegram' ? '/^[1-9][0-9]{0,18}$/D' : '/^[1-9][0-9]{0,19}(?:@lid)?$/D', $address)
            || ($verifiedPhone !== null && ($channel !== 'whatsapp' || !preg_match('/^\+[1-9][0-9]{6,14}$/D', $verifiedPhone)))
            || ($channel === 'whatsapp' && !str_ends_with($address, '@lid') && ($verifiedPhone === null || $address !== substr($verifiedPhone, 1)))) {
            throw new \InvalidArgumentException('Invalid channel identity.');
        }
    }

    public function queueUserReply(int $userId, string $text, array $options = [], ?int $identityId = null): void
    {
        $this->transaction(function () use ($userId, $text, $options, $identityId): void {
            foreach ($this->replyIdentities($userId, $identityId) as $identity) {
                $message = $text;
                if ($identity['channel'] === 'whatsapp') {
                    $message = $this->whatsappMenu($identity['id'], $text, $options);
                }
                $this->job('message', null, $userId, null, ['text' => $message, 'options' => $identity['channel'] === 'telegram' ? $options : [], 'identity_id' => $identity['id'], 'address' => $identity['address']], channel: $identity['channel']);
            }
        });
    }

    public function queueUserPhoto(int $userId, string $caption, ?int $identityId = null): void
    {
        foreach ($this->replyIdentities($userId, $identityId) as $identity) {
            if ($identity['channel'] === 'whatsapp') {
                $this->queueUserReply($userId, $caption, [], $identity['id']);
            } else {
                $this->job('photo', null, $userId, null, ['caption' => $caption, 'identity_id' => $identity['id'], 'address' => $identity['address']]);
            }
        }
    }

    private function replyIdentities(int $userId, ?int $identityId): array
    {
        if ($identityId !== null) {
            $identity = $this->identityById($identityId);
            if ($identity === null || $identity['user_id'] !== $userId) { throw new \InvalidArgumentException('Identity does not belong to user.'); }
            return [$identity];
        }
        return $this->identities($userId);
    }

    private function whatsappMenu(int $identityId, string $text, array $options): string
    {
        $markup = $options['reply_markup'] ?? [];
        if (is_string($markup)) { $markup = json_decode($markup, true, flags: JSON_THROW_ON_ERROR); }
        if (!empty($markup['remove_keyboard'])) {
            $this->run('DELETE FROM channel_menus WHERE identity_id=?', [$identityId]);
            return $text;
        }
        $rows = $markup['inline_keyboard'] ?? $markup['keyboard'] ?? null;
        if ($rows === null) { return $text; }
        $mapping = [];
        $labels = [];
        foreach ($rows as $row) {
            foreach ($row as $button) {
                $label = is_array($button) ? $button['text'] : $button;
                $number = count($mapping) + 1;
                $mapping[$number] = is_array($button) ? ($button['callback_data'] ?? $label) : $label;
                $labels[] = $number . '. ' . $label;
            }
        }
        $this->run('INSERT INTO channel_menus(identity_id,options,expires_at) VALUES (?,?,?) ON CONFLICT(identity_id) DO UPDATE SET options=excluded.options,expires_at=excluded.expires_at', [$identityId, json_encode($mapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), time() + 900]);
        return $labels === [] ? $text : $text . "\n\n" . implode("\n", $labels);
    }

    public function menu(int $identityId): array
    {
        $row = $this->one('SELECT options,expires_at FROM channel_menus WHERE identity_id=? AND expires_at>?', [$identityId, time()]);
        if ($row === null) { return []; }
        return ['options' => json_decode($row['options'], true, flags: JSON_THROW_ON_ERROR), 'expires_at' => $row['expires_at']];
    }

    public function acceptChannelEvent(string $channel, string $eventId, int $sequence, ?int $sourceDate = null): bool
    {
        if ($channel === '' || $eventId === '' || $sequence < 0) { throw new \InvalidArgumentException('Invalid channel event.'); }
        return $this->transaction(function () use ($channel, $eventId, $sequence, $sourceDate): bool {
            $this->run('INSERT INTO channel_cursors(channel,sequence) VALUES (?,?) ON CONFLICT(channel) DO UPDATE SET sequence=MAX(sequence,excluded.sequence)', [$channel, $sequence]);
            if ($this->run('INSERT OR IGNORE INTO channel_events(channel,event_id,sequence) VALUES (?,?,?)', [$channel, $eventId, $sequence])->rowCount() !== 1) { return false; }
            $cutoff = $this->one("SELECT value FROM runtime_state WHERE name='reset_cutoff'");
            return $sourceDate === null || $cutoff === null || $sourceDate > (int) $cutoff['value'];
        });
    }

    public function channelCursor(string $channel): int { return (int) ($this->one('SELECT sequence FROM channel_cursors WHERE channel=?', [$channel])['sequence'] ?? 0); }

    public function contactOwner(string $field, string $value, int $exceptId): ?array
    {
        $reservation = $this->contactReservation($field, $value);
        if ($reservation === null) { return null; }
        $owner = $this->effectiveUserId($reservation['user_id']);
        if ($owner === null || $owner === $this->effectiveUserId($exceptId)) { return null; }
        return $this->user($owner);
    }

    private function contactReservation(string $field, string $value): ?array
    {
        if (!in_array($field, ['phone', 'email'], true)) { throw new \InvalidArgumentException('Unknown contact.'); }
        return $this->one('SELECT user_id FROM contact_reservations WHERE field=? AND value=?', [$field, $value]);
    }

    private function effectiveUserId(int $id): ?int
    {
        $visited = [];
        // Audit rows retain physical ownership; only approved links delegate it to a current person.
        for ($depth = 0; $depth < 64; ++$depth) {
            if (isset($visited[$id])) { return null; }
            $visited[$id] = true;
            $user = $this->user($id);
            if ($user === null) { return null; }
            $links = $this->run("SELECT DISTINCT target_user_id FROM link_requests WHERE source_user_id=? AND status='approved' LIMIT 2", [$id])->fetchAll(PDO::FETCH_COLUMN);
            if ($links === []) { return $user['status'] === 'linked' ? null : $id; }
            if (count($links) !== 1) { return null; }
            $id = (int) $links[0];
        }
        return null;
    }

    public function createLinkRequest(int $sourceIdentityId, int $targetUserId, int $now, bool $manual = false): ?array
    {
        return $this->transaction(function () use ($sourceIdentityId, $targetUserId, $now, $manual): ?array {
            $source = $this->identityById($sourceIdentityId);
            $target = $this->user($targetUserId);
            if ($source === null || $source['retired'] || $target === null || $source['user_id'] === $targetUserId || $target['status'] === 'linked') { return null; }
            $sourceUser = $this->user($source['user_id']);
            $conflict = $this->one('SELECT * FROM identity_conflicts WHERE source_identity_id=? AND target_user_id=?', [$sourceIdentityId, $targetUserId]);
            if ($target['submitted_at'] === null && (!$manual || $conflict === null)) { return null; }
            $sameChannel = $this->one('SELECT id FROM channel_identities WHERE user_id=? AND channel=? AND retired=0', [$targetUserId, $source['channel']]);
            if (($sameChannel !== null && (!$manual || $conflict === null)) || (!$manual && $sourceUser['submitted_at'] !== null)
                || $sourceUser['status'] === 'linked' || count($this->identities($source['user_id'])) !== 1) { return null; }
            $confirm = $this->one('SELECT id FROM channel_identities WHERE user_id=? AND retired=0 ORDER BY id LIMIT 1', [$targetUserId]);
            if ($confirm === null) { return null; }
            $this->run("UPDATE link_requests SET status='expired' WHERE source_identity_id=? AND status='pending' AND expires_at<=?", [$sourceIdentityId, $now]);
            $active = $this->one("SELECT * FROM link_requests WHERE source_identity_id=? AND status='pending'", [$sourceIdentityId]);
            if ($active !== null) {
                if (!$manual || $active['manual'] || $active['target_user_id'] !== $targetUserId) { return null; }
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $this->run('UPDATE link_requests SET manual=1,token_hash=? WHERE id=?', [hash('sha256', $token), $active['id']]);
                return $this->linkRequest($active['id']) + ['token' => $token];
            }
            if ((int) $this->run('SELECT COUNT(*) FROM link_requests WHERE source_identity_id=? AND created_at>?', [$sourceIdentityId, $now - 3600])->fetchColumn() >= 3) { return null; }
            // 32 random bytes encoded to fit Telegram's 64-byte callback payload limit.
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->run('INSERT INTO link_requests(source_identity_id,source_user_id,target_user_id,confirm_identity_id,token_hash,created_at,expires_at,manual) VALUES (?,?,?,?,?,?,?,?)', [$sourceIdentityId, $source['user_id'], $targetUserId, $confirm['id'], hash('sha256', $token), $now, $now + 900, (int) $manual]);
            return $this->linkRequest((int) $this->db->lastInsertId()) + ['token' => $token];
        });
    }

    public function linkRequest(int $id): ?array
    {
        return $this->one('SELECT id,source_identity_id,source_user_id,target_user_id,confirm_identity_id,created_at,expires_at,manual,status,actor,resolved_at,reason FROM link_requests WHERE id=?', [$id]);
    }

    public function pendingLinks(): array
    {
        return $this->run("SELECT id,source_identity_id,source_user_id,target_user_id,confirm_identity_id,created_at,expires_at,manual,status,actor,resolved_at,reason FROM link_requests WHERE status='pending' AND expires_at>? ORDER BY id LIMIT 100", [time()])->fetchAll();
    }

    public function confirmLink(string $token, int $confirmIdentityId, int $now): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) { return null; }
        return $this->transaction(function () use ($token, $confirmIdentityId, $now): ?array {
            $request = $this->one("SELECT * FROM link_requests WHERE token_hash=? AND confirm_identity_id=? AND manual=0 AND status='pending' AND expires_at>?", [hash('sha256', $token), $confirmIdentityId, $now]);
            if ($request === null) { return null; }
            $source = $this->identityById($request['source_identity_id']);
            $confirm = $this->identityById($confirmIdentityId);
            if ($source === null || $confirm === null || $confirm['user_id'] !== $request['target_user_id'] || $confirm['retired'] || $this->user($source['user_id'])['submitted_at'] !== null) { return null; }
            return $this->attachLink($request, null, null, $now);
        });
    }

    public function resolveLink(int $id, int $actor, bool $approve, string $reason, int $now): ?array
    {
        if ($actor < 1 || trim($reason) === '' || mb_strlen($reason) > 1024) { return null; }
        return $this->transaction(function () use ($id, $actor, $approve, $reason, $now): ?array {
            $request = $this->one("SELECT * FROM link_requests WHERE id=? AND status='pending' AND expires_at>?", [$id, $now]);
            if ($request === null) { return null; }
            if (!$approve) {
                $this->run("UPDATE link_requests SET status='rejected',actor=?,reason=?,resolved_at=? WHERE id=?", [$actor, trim($reason), $now, $id]);
                return $this->linkRequest($id);
            }
            return $this->attachLink($request, $actor, trim($reason), $now);
        });
    }

    private function attachLink(array $request, ?int $actor, ?string $reason, int $now): ?array
    {
        $identity = $this->identityById($request['source_identity_id']);
        $target = $this->user($request['target_user_id']);
        if ($identity === null || $identity['retired'] || $identity['user_id'] !== $request['source_user_id'] || $target === null || $target['status'] === 'linked'
            || count($this->identities($request['source_user_id'])) !== 1) { return null; }
        $sameChannel = $this->one('SELECT * FROM channel_identities WHERE user_id=? AND channel=? AND retired=0', [$target['id'], $identity['channel']]);
        if ($sameChannel !== null) {
            $conflict = $this->one('SELECT * FROM identity_conflicts WHERE source_identity_id=? AND target_identity_id=? AND target_user_id=?', [$identity['id'], $sameChannel['id'], $target['id']]);
            if ($actor === null || !$request['manual'] || $conflict === null || $sameChannel['verified_phone'] !== $conflict['verified_phone']) { return null; }
            $this->run('UPDATE channel_identities SET enabled=0,retired=1 WHERE id=?', [$identity['id']]);
            $this->run('UPDATE channel_identity_aliases SET identity_id=? WHERE identity_id=?', [$sameChannel['id'], $identity['id']]);
            $this->run('INSERT INTO channel_identity_aliases(channel,address,identity_id) VALUES (?,?,?) ON CONFLICT(channel,address) DO UPDATE SET identity_id=excluded.identity_id', [$identity['channel'], $identity['address'], $sameChannel['id']]);
            $attachedId = $sameChannel['id'];
        } else {
            if ($target['submitted_at'] === null) { return null; }
            $this->run('UPDATE channel_identities SET user_id=?,enabled=1 WHERE id=?', [$target['id'], $identity['id']]);
            $attachedId = $identity['id'];
        }
        $this->run("UPDATE users SET status='linked',subscribed=0,revision=revision+1 WHERE id=?", [$request['source_user_id']]);
        $this->run('DELETE FROM channel_menus WHERE identity_id=?', [$identity['id']]);
        $this->run("UPDATE link_requests SET status='approved',actor=?,reason=?,resolved_at=? WHERE id=?", [$actor, $reason, $now, $request['id']]);
        $this->run("UPDATE link_requests SET status='superseded',resolved_at=? WHERE source_identity_id=? AND status='pending' AND id!=?", [$now, $identity['id'], $request['id']]);
        return ['id' => $request['id'], 'user_id' => $target['id'], 'identity_id' => $attachedId, 'channel' => $identity['channel']];
    }

    public function queueReply(int $chatId, string $text, array $options = []): void
    {
        $this->job('message', null, $chatId, null, ['text' => $text, 'options' => $options]);
    }

    public function queuePhoto(int $chatId, string $caption): void
    {
        $this->job('photo', null, $chatId, null, ['caption' => $caption]);
    }

    public function queueKeyboard(int $actor, int $messageId, int $draftId, int $version, array $rows): void
    {
        $this->job('edit_keyboard', null, $actor, null, ['message_id' => $messageId, 'draft_id' => $draftId, 'version' => $version, 'rows' => $rows]);
    }

    public function queueAttachmentNotice(int $draftId): void
    {
        $draft = $this->draft($draftId);
        $files = $this->draftAttachments($draftId);
        $attachmentId = $files[array_key_last($files)]['id'];
        $job = $this->one("SELECT * FROM jobs WHERE kind='attachment_notice' AND status='pending' AND json_extract(payload,'$.draft_id')=CAST(? AS INTEGER)", [$draftId]);
        if ($job === null) {
            $this->job('attachment_notice', null, $draft['admin_id'], null, ['draft_id' => $draftId, 'attachment_ids' => [$attachmentId]]);
            $jobId = (int) $this->db->lastInsertId();
        } else {
            $jobId = $job['id'];
            $payload = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR);
            $payload['attachment_ids'][] = $attachmentId;
            $this->updateJobPayload($jobId, $payload);
        }
        $this->run('UPDATE jobs SET next_at=? WHERE id=?', [time() + 2, $jobId]);
    }

    public function finishAttachmentNotice(int $jobId, int $now): void
    {
        $this->transaction(function () use ($jobId, $now): void {
            // Reload under the write lock so arrivals cannot be lost while the worker counts a batch.
            $job = $this->one("SELECT * FROM jobs WHERE id=? AND status='pending'", [$jobId]);
            if ($job === null || $job['next_at'] > $now) { return; }
            $payload = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR);
            $draft = $this->draft($payload['draft_id']);
            if (!$draft || !in_array($draft['status'], ['draft', 'changes'], true)) {
                $this->completeJob($jobId, 'skipped');
                return;
            }
            $count = 0;
            foreach ($this->draftAttachments($draft['id']) as $file) {
                if (!in_array($file['id'], $payload['attachment_ids'], true)) { continue; }
                if ($file['status'] === 'pending'
                    && !$this->one("SELECT id FROM jobs WHERE kind='attachment_download' AND status='failed' AND json_extract(payload,'$.attachment_id')=CAST(? AS INTEGER)", [$file['id']])) {
                    $this->run('UPDATE jobs SET next_at=? WHERE id=?', [$now + 2, $jobId]);
                    return;
                }
                if ($file['status'] === 'ready') { ++$count; }
            }
            if ($count > 0) {
                $this->queueReply($draft['admin_id'], Messages::text('RU', 'attachments_added', ['count' => $count, 'id' => $draft['id']]),
                    Messages::attachmentDoneKeyboard($draft['id'], $draft['version']));
            }
            $this->completeJob($jobId);
        });
    }

    public function queueBroadcastNotice(int $chatId, array $broadcast, string $key): void
    {
        if (!$this->directoryEnabled) {
            $this->queueReply($chatId, Messages::text('RU', $key, $broadcast));
            return;
        }
        $this->job('message', $broadcast['id'], $chatId, null, ['text' => '', 'options' => [], 'notice_key' => $key]);
    }

    public function queueCallback(string $id): void { $this->job('callback', null, null, null, ['id' => $id]); }

    public function createBroadcast(int $adminId, string $text): array
    {
        $this->run('INSERT INTO broadcasts(admin_id,text,created_at) VALUES (?,?,?)', [$adminId, $text, time()]);
        $id = (int) $this->db->lastInsertId();
        if ($this->directoryEnabled) {
            $this->run("UPDATE broadcasts SET status='preparing_audience' WHERE id=?", [$id]);
            $this->job('audience_snapshot', $id, null, null, ['snapshot_id' => bin2hex(random_bytes(16))]);
            return ['id' => $id, 'recipients' => 0];
        }
        $users = $this->run("SELECT id,language FROM users WHERE status='approved' AND NOT EXISTS (SELECT 1 FROM channel_identities i WHERE i.user_id=users.id AND i.channel='telegram' AND i.address=? AND i.retired=0) ORDER BY id", [(string) $adminId])->fetchAll();
        foreach ($users as $user) {
            $this->job('translate', $id, null, $user['language'], [], true);
            $this->job('translate_subject', $id, null, $user['language'], [], true);
            $this->snapshotDeliveries($id, $user['id'], $user['language'], true);
        }
        return ['id' => $id, 'recipients' => count($users)];
    }

    public function broadcast(int $id): ?array { return $this->one('SELECT * FROM broadcasts WHERE id=?', [$id]); }

    public function directoryEnabled(): bool { return $this->directoryEnabled; }

    public function bootstrapDirectory(): void
    {
        if (!$this->directoryEnabled) { return; }
        $this->transaction(function (): void {
            foreach ($this->run("SELECT DISTINCT user_id FROM channel_identities WHERE channel='telegram' AND retired=0")->fetchAll() as $row) {
                $this->syncDirectoryUser($row['user_id']);
            }
        });
    }

    private function syncDirectoryUser(int $userId, bool $reactivate = false): void
    {
        if (!$this->directoryEnabled) { return; }
        $user = $this->user($userId);
        if (!$user || !\Broadcast\Domain\PhoneNumber::normalize($user['phone'])) { return; }
        $identity = $this->one("SELECT address FROM channel_identities WHERE user_id=? AND channel='telegram' AND retired=0", [$userId]);
        if (!$identity) { return; }
        if ($user['status'] === 'approved') { $this->linkDirectoryPhone($userId, $user['phone']); }
        $change = ['type' => 'telegram', 'user_id' => (int) $identity['address'], 'phone' => $user['phone'], 'email' => $user['email'] ?: null, 'language' => $user['language'], 'status' => $user['status'], 'subscribed' => (bool) $user['subscribed']];
        $fingerprint = hash('sha256', json_encode($change, JSON_THROW_ON_ERROR));
        $previous = $this->one('SELECT * FROM directory_local_state WHERE user_id=?', [$userId]);
        if (!$reactivate && $previous && $previous['fingerprint'] === $fingerprint) { return; }
        $change['revision'] = $this->nextDirectoryRevision();
        $change['reactivate'] = $reactivate;
        $this->run('INSERT INTO directory_local_state(user_id,revision,fingerprint) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET revision=excluded.revision,fingerprint=excluded.fingerprint', [$userId, $change['revision'], $fingerprint]);
        $this->job('directory_sync', null, $userId, null, ['operation_id' => bin2hex(random_bytes(16)), 'change' => $change]);
    }

    private function nextDirectoryRevision(): int
    {
        $floor = max((int) ($this->one("SELECT value FROM runtime_state WHERE name='directory_revision'")['value'] ?? 0),
            (int) ($this->one('SELECT MAX(revision) AS revision FROM directory_local_state')['revision'] ?? 0),
            (int) ($this->one('SELECT MAX(revision) AS revision FROM directory_links')['revision'] ?? 0));
        $this->run("INSERT INTO runtime_state(name,value) VALUES ('directory_revision',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value", [$floor + 1]);
        return $floor + 1;
    }

    private function linkDirectoryPhone(int $userId, string $phone): void
    {
        $link = $this->one('SELECT d.* FROM directory_links d JOIN users u ON u.id=d.user_id WHERE u.phone=? AND d.user_id!=?', [$phone, $userId]);
        if (!$link || $this->one('SELECT 1 FROM directory_links WHERE user_id=?', [$userId])
            || $this->one("SELECT 1 FROM channel_identities WHERE user_id=? AND channel='telegram' AND retired=0", [$link['user_id']])) { return; }
        $oldWhatsApp = $this->one("SELECT * FROM channel_identities WHERE user_id=? AND channel='whatsapp' AND retired=0", [$link['user_id']]);
        if ($oldWhatsApp && ($oldWhatsApp['verified_phone'] !== $phone || $this->one("SELECT 1 FROM channel_identities WHERE user_id=? AND channel='whatsapp'", [$userId]))) { return; }
        $this->run('INSERT INTO directory_link_audit(contact_id,previous_user_id,user_id,created_at) VALUES (?,?,?,?)', [$link['contact_id'], $link['user_id'], $userId, time()]);
        if ($oldWhatsApp) { $this->run('UPDATE channel_identities SET user_id=? WHERE id=?', [$userId, $oldWhatsApp['id']]); }
        $this->run('UPDATE directory_links SET user_id=? WHERE contact_id=?', [$userId, $link['contact_id']]);
    }

    public function directoryBlocked(int $contactId): bool
    {
        return (bool) ($this->one('SELECT blocked FROM directory_links WHERE contact_id=?', [$contactId])['blocked'] ?? false);
    }

    public function publishAudience(int $broadcastId, \Broadcast\Domain\RecipientSnapshot $snapshot): bool
    {
        return $this->transaction(function () use ($broadcastId, $snapshot): bool {
            $broadcast = $this->broadcast($broadcastId);
            if (!$broadcast || !$broadcast['approved_by'] || $broadcast['status'] !== 'preparing_audience') { return false; }
            foreach ($snapshot->recipients as $recipient) {
                if ($recipient['telegram_user_id'] === $broadcast['admin_id'] || $this->directoryBlocked($recipient['contact_id'])) { continue; }
                $link = $this->one('SELECT * FROM directory_links WHERE contact_id=?', [$recipient['contact_id']]);
                if ($link === null) {
                    $identity = $recipient['telegram_user_id'] !== null
                        ? $this->identity('telegram', (string) $recipient['telegram_user_id']) : null;
                    $identity ??= $this->ensureIdentity('whatsapp', substr($recipient['phone'], 1), $recipient['phone']);
                    $userId = $identity['user_id'];
                    // Imported contacts have no Telegram application; never overwrite a real application.
                    if ($this->one("SELECT id FROM channel_identities WHERE user_id=? AND channel='telegram'", [$userId]) === null) {
                        $this->run("UPDATE users SET phone=?,language=?,status='approved',completed=1,choosing_language=0 WHERE id=?", [$recipient['phone'], $recipient['language'], $userId]);
                    }
                    $this->run('INSERT INTO directory_links(contact_id,user_id) VALUES (?,?)', [$recipient['contact_id'], $userId]);
                } else { $userId = $link['user_id']; }
                $payload = ['contact_id' => $recipient['contact_id'], 'address' => $recipient['address']];
                if ($recipient['channel'] === 'email') { $payload['email'] = $recipient['address']; }
                $this->job('translate', $broadcastId, null, $recipient['language'], [], true);
                $this->job('translate_subject', $broadcastId, null, $recipient['language'], [], true);
                $this->job('delivery', $broadcastId, $userId, $recipient['language'], $payload, true, $recipient['channel']);
            }
            $this->run("UPDATE broadcasts SET status='preparing',audience_snapshot=? WHERE id=?", [$snapshot->id, $broadcastId]);
            $recipients = (int) $this->run("SELECT COUNT(DISTINCT user_id) FROM jobs WHERE broadcast_id=? AND kind='delivery'", [$broadcastId])->fetchColumn();
            foreach ($this->run("SELECT id,payload FROM jobs WHERE broadcast_id=? AND kind='message' AND json_extract(payload,'$.notice_key') IS NOT NULL", [$broadcastId])->fetchAll() as $notice) {
                $payload = json_decode($notice['payload'], true, flags: JSON_THROW_ON_ERROR);
                $payload['text'] = Messages::text('RU', $payload['notice_key'], ['id' => $broadcastId, 'recipients' => $recipients]);
                unset($payload['notice_key']);
                $this->updateJobPayload($notice['id'], $payload);
            }
            return true;
        });
    }

    public function addCatchup(int $userId, string $language): bool
    {
        $broadcast = $this->catchupBroadcast($userId);
        if ($broadcast === null) { return false; }
        if ($this->directoryEnabled) { return $this->queueDirectoryCatchup($broadcast['id'], $userId); }
        $this->job('translate', $broadcast['id'], null, $language);
        $this->job('translate_subject', $broadcast['id'], null, $language);
        if ($this->snapshotDeliveries($broadcast['id'], $userId, $language)) {
            $this->run('UPDATE broadcasts SET reported=0 WHERE id=?', [$broadcast['id']]);
        }
        return true;
    }

    public function addChannelCatchup(int $userId, string $language, string $channel): bool
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) { throw new \InvalidArgumentException('Unknown delivery channel.'); }
        if ($this->directoryEnabled && $channel === 'whatsapp') { return false; }
        $broadcast = $this->catchupBroadcast($userId);
        if ($broadcast !== null && $this->directoryEnabled) { return $this->queueDirectoryCatchup($broadcast['id'], $userId, $channel); }
        if ($broadcast === null || !$this->snapshotDeliveries($broadcast['id'], $userId, $language, false, $channel)) { return false; }
        $this->job('translate', $broadcast['id'], null, $language);
        $this->job('translate_subject', $broadcast['id'], null, $language);
        $this->run('UPDATE broadcasts SET reported=0 WHERE id=?', [$broadcast['id']]);
        return true;
    }

    private function queueDirectoryCatchup(int $broadcastId, int $userId, ?string $onlyChannel = null): bool
    {
        if ($this->one("SELECT id FROM jobs WHERE kind='directory_catchup' AND broadcast_id=? AND user_id=? AND status IN ('pending','sending')", [$broadcastId, $userId])) { return false; }
        return $this->job('directory_catchup', $broadcastId, $userId, null, ['snapshot_id' => bin2hex(random_bytes(16)), 'only_channel' => $onlyChannel]);
    }

    public function publishDirectoryCatchup(int $broadcastId, int $userId, \Broadcast\Domain\RecipientSnapshot $snapshot, ?string $onlyChannel = null): bool
    {
        return $this->transaction(function () use ($broadcastId, $userId, $snapshot, $onlyChannel): bool {
            $user = $this->user($userId);
            $broadcast = $this->broadcast($broadcastId);
            if (!$user || $user['status'] !== 'approved' || !$broadcast || !$broadcast['approved_by']) { return false; }
            $telegram = $this->one("SELECT address FROM channel_identities WHERE user_id=? AND channel='telegram' AND retired=0", [$userId]);
            if (!$telegram || (int) $telegram['address'] === $broadcast['admin_id']) { return false; }
            $added = false;
            foreach ($snapshot->recipients as $recipient) {
                if ($recipient['telegram_user_id'] !== (int) $telegram['address'] || !in_array($recipient['channel'], ['telegram','email'], true)
                    || $recipient['phone'] !== $user['phone'] || ($onlyChannel !== null && $recipient['channel'] !== $onlyChannel) || $this->directoryBlocked($recipient['contact_id'])) { continue; }
                $link = $this->one('SELECT user_id FROM directory_links WHERE contact_id=?', [$recipient['contact_id']]);
                if ($link === null) {
                    if ($this->one('SELECT contact_id FROM directory_links WHERE user_id=?', [$userId])) { continue; }
                    $this->run('INSERT INTO directory_links(contact_id,user_id) VALUES (?,?)', [$recipient['contact_id'], $userId]);
                } elseif ($link['user_id'] !== $userId) {
                    $this->linkDirectoryPhone($userId, $user['phone']);
                    if (($this->one('SELECT user_id FROM directory_links WHERE contact_id=?', [$recipient['contact_id']])['user_id'] ?? null) !== $userId) { continue; }
                }
                // A phone merge can change local user IDs without creating a new recipient.
                if ($this->one("SELECT id FROM jobs WHERE kind='delivery' AND broadcast_id=? AND channel=? AND json_extract(payload,'$.contact_id')=CAST(? AS INTEGER)", [$broadcastId, $recipient['channel'], $recipient['contact_id']])) { continue; }
                $payload = ['contact_id' => $recipient['contact_id'], 'address' => $recipient['address'], 'snapshot_id' => $snapshot->id];
                if ($recipient['channel'] === 'email') { $payload['email'] = $recipient['address']; }
                if (!$this->job('delivery', $broadcastId, $userId, $recipient['language'], $payload, false, $recipient['channel'])) { continue; }
                $this->job('translate', $broadcastId, null, $recipient['language']);
                $this->job('translate_subject', $broadcastId, null, $recipient['language']);
                $added = true;
            }
            if ($added) { $this->run('UPDATE broadcasts SET reported=0 WHERE id=?', [$broadcastId]); }
            return $added;
        });
    }

    private function catchupBroadcast(int $userId): ?array
    {
        $user = $this->user($userId);
        if ($user === null || $user['status'] !== 'approved') { return null; }
        $broadcast = $this->one("SELECT id,admin_id FROM broadcasts WHERE status='ready' AND approved_by>0 ORDER BY id DESC LIMIT 1");
        if ($broadcast === null || $this->one("SELECT id FROM channel_identities WHERE user_id=? AND channel='telegram' AND address=? AND retired=0", [$userId, (string) $broadcast['admin_id']]) !== null) { return null; }
        return $broadcast;
    }

    private function snapshotDeliveries(int $broadcastId, int $userId, string $language, bool $initial = false, ?string $onlyChannel = null): bool
    {
        $added = false;
        foreach ($this->identities($userId) as $identity) {
            if ($onlyChannel !== null && $identity['channel'] !== $onlyChannel) { continue; }
            $inserted = $this->job('delivery', $broadcastId, $userId, $language, ['identity_id' => $identity['id'], 'address' => $identity['address']], $initial, $identity['channel']);
            $added = $added || $inserted;
        }
        if ($onlyChannel === null) {
            $inserted = $this->job('delivery', $broadcastId, $userId, $language, ['email' => $this->user($userId)['email']], $initial, 'email');
            $added = $added || $inserted;
        }
        return $added;
    }

    public function contactTaken(string $field, string $value, int $exceptId): bool
    {
        $reservation = $this->contactReservation($field, $value);
        if ($reservation === null) { return false; }
        $owner = $this->effectiveUserId($reservation['user_id']);
        // Broken/cyclic audit chains remain reserved and never authorize another profile.
        return $owner === null || $owner !== $this->effectiveUserId($exceptId);
    }

    public function submitApplication(int $id): bool
    {
        return $this->transaction(function () use ($id): bool {
            $user = $this->user($id);
            if (!$user || !in_array($user['status'], ['draft', 'changes', 'unsubscribed'], true) || $this->effectiveUserId($id) !== $id) { return false; }
            if ($this->contactTaken('phone', $user['phone'], $id) || $this->contactTaken('email', $user['email'], $id)) { return false; }
            // Ordinary corrections release only this live profile's obsolete reservations.
            // Reservations held by archived linked sources remain intact under their original IDs.
            $this->run("DELETE FROM contact_reservations WHERE user_id=? AND ((field='phone' AND value!=?) OR (field='email' AND value!=?))", [$id, $user['phone'], $user['email']]);
            foreach (['phone', 'email'] as $field) {
                $this->run('INSERT INTO contact_reservations(field,value,user_id) VALUES (?,?,?) ON CONFLICT(field,value) DO NOTHING', [$field, $user[$field], $id]);
            }
            $this->saveLegacyContacts($user);
            $this->run("UPDATE users SET status='pending',submitted_at=COALESCE(submitted_at,?),revision=revision+1,completed=1,step='review',edit_field='' WHERE id=?", [time(), $id]);
            return true;
        });
    }

    private function saveLegacyContacts(array $user): void
    {
        $existing = $this->one('SELECT phone,email FROM contacts WHERE user_id=?', [$user['id']]);
        $values = [];
        foreach (['phone', 'email'] as $field) {
            $conflict = $this->one("SELECT user_id FROM contacts WHERE $field=? AND user_id!=?", [$user[$field], $user['id']]);
            if ($conflict !== null && $existing === null) { return; }
            $values[] = $conflict === null ? $user[$field] : $existing[$field];
        }
        // This legacy row retains history. Current values live in users; reservations enforce uniqueness.
        $this->run('INSERT INTO contacts(user_id,phone,email) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET phone=excluded.phone,email=excluded.email', [$user['id'], ...$values]);
    }

    public function applications(?int $id = null): array
    {
        return $id === null ? $this->run("SELECT * FROM users WHERE status='pending' ORDER BY submitted_at,id LIMIT 30")->fetchAll()
            : $this->run('SELECT * FROM users WHERE id=? AND submitted_at IS NOT NULL', [$id])->fetchAll();
    }

    public function decideApplication(int $id, int $revision, string $status, int $actor): bool
    {
        if (!in_array($status, ['approved', 'changes', 'rejected', 'draft'], true)) { return false; }
        $from = $status === 'draft' ? 'rejected' : 'pending';
        return $this->transaction(function () use ($id, $revision, $status, $from): bool {
            $changed = $this->run("UPDATE users SET status=?,revision=revision+1,subscribed=?,approved_at=?,edit_field='',step='review' WHERE id=? AND revision=? AND status=?", [$status, (int) ($status === 'approved'), $status === 'approved' ? time() : null, $id, $revision, $from])->rowCount() === 1;
            if ($changed) {
                $this->syncDirectoryUser($id, $status === 'approved');
                if ($status === 'approved') { $this->run('UPDATE directory_links SET blocked=0 WHERE user_id=?', [$id]); }
            }
            return $changed;
        });
    }

    public function unsubscribeUser(int $id): bool
    {
        // Every delivery checks status; the Telegram-specific subscribed flag remains independent.
        return $this->transaction(function () use ($id): bool {
            $changed = $this->run("UPDATE users SET subscribed=0,status='unsubscribed',revision=revision+1 WHERE id=? AND status='approved'", [$id])->rowCount() === 1;
            if ($changed && $this->directoryEnabled) {
                $revision = $this->nextDirectoryRevision();
                $this->run('UPDATE directory_links SET blocked=1,revision=? WHERE user_id=?', [$revision, $id]);
                $link = $this->one('SELECT * FROM directory_links WHERE user_id=?', [$id]);
                if ($link) {
                    $this->job('directory_sync', null, $id, null, ['operation_id' => bin2hex(random_bytes(16)), 'change' => ['type' => 'subscription', 'phone' => $this->user($id)['phone'], 'revision' => $revision, 'active' => false]]);
                }
                $this->syncDirectoryUser($id);
            }
            return $changed;
        });
    }

    public function session(int $actor): ?array
    {
        $row = $this->one('SELECT payload FROM staff_sessions WHERE actor=?', [$actor]);
        return $row ? json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function saveSession(int $actor, ?array $session): void
    {
        if ($session === null) { $this->run('DELETE FROM staff_sessions WHERE actor=?', [$actor]); return; }
        $this->run('INSERT INTO staff_sessions(actor,payload) VALUES (?,?) ON CONFLICT(actor) DO UPDATE SET payload=excluded.payload', [$actor, json_encode($session, JSON_THROW_ON_ERROR)]);
    }

    public function createDraft(int $admin, string $text): array
    {
        $this->run('INSERT INTO drafts(admin_id,text,created_at,updated_at) VALUES (?,?,?,?)', [$admin, $text, time(), time()]);
        return $this->draft((int) $this->db->lastInsertId());
    }

    public function latestEditableDraft(int $admin): ?array
    {
        return $this->one("SELECT * FROM drafts WHERE admin_id=? AND status IN ('draft','changes') ORDER BY id DESC LIMIT 1", [$admin]);
    }

    public function stageDraftAttachment(int $draftId, int $version, string $fileId, string $name, int $size): bool
    {
        try { \Broadcast\Domain\Attachment::validateName($name); } catch (\InvalidArgumentException) { return false; }
        if ($fileId === '' || strlen($fileId) > 1024 || $size < 0 || $size > \Broadcast\Domain\Attachment::MAX_BYTES) { return false; }
        return $this->transaction(function () use ($draftId, $version, $fileId, $name, $size): bool {
            $draft = $this->draft($draftId);
            if (!$draft || $draft['version'] !== $version || !in_array($draft['status'], ['draft','changes'], true) || count($this->draftAttachments($draftId)) >= 10) { return false; }
            $this->run('INSERT INTO attachments(file_id,display_name,expected_size,created_at) VALUES (?,?,?,?)', [$fileId, $name, $size, time()]);
            $id = (int) $this->db->lastInsertId();
            $position = (int) ($this->one('SELECT MAX(position) AS p FROM draft_attachments WHERE draft_id=?', [$draftId])['p'] ?? 0) + 1;
            $this->run('INSERT INTO draft_attachments VALUES (?,?,?)', [$draftId, $id, $position]);
            $this->saveDraft($draftId, $version, ['text' => $draft['text']]);
            $this->job('attachment_download', null, $draft['admin_id'], null, ['attachment_id' => $id, 'staff_id' => $draft['admin_id']]);
            return true;
        });
    }

    public function removeDraftAttachment(int $draftId, int $version, int $attachmentId): bool
    {
        return $this->transaction(function () use ($draftId, $version, $attachmentId): bool {
            $draft = $this->draft($draftId);
            if (!$draft || $draft['version'] !== $version || !in_array($draft['status'], ['draft','changes'], true)) { return false; }
            if (!$this->run('DELETE FROM draft_attachments WHERE draft_id=? AND attachment_id=?', [$draftId, $attachmentId])->rowCount()) { return false; }
            return $this->saveDraft($draftId, $version, ['text' => $draft['text']]);
        });
    }

    public function draftAttachments(int $draftId): array
    {
        return $this->run('SELECT a.*,d.position FROM attachments a JOIN draft_attachments d ON d.attachment_id=a.id WHERE d.draft_id=? ORDER BY d.position', [$draftId])->fetchAll();
    }

    public function broadcastAttachments(int $broadcastId): array
    {
        return $this->run('SELECT a.*,b.position FROM attachments a JOIN broadcast_attachments b ON b.attachment_id=a.id WHERE b.broadcast_id=? ORDER BY b.position', [$broadcastId])->fetchAll();
    }

    public function attachment(int $id): ?array { return $this->one('SELECT * FROM attachments WHERE id=?', [$id]); }

    public function queueAttachmentCleanup(int $now): void
    {
        $next = (int) ($this->one("SELECT value FROM runtime_state WHERE name='attachment_cleanup_at'")['value'] ?? 0);
        if ($now < $next) { return; }
        $this->transaction(function () use ($now): void {
            if (!$this->one("SELECT 1 FROM jobs WHERE kind='attachment_cleanup' AND status='pending'")) {
                $this->job('attachment_cleanup', null, null, null);
            }
            $this->run("INSERT INTO runtime_state(name,value) VALUES ('attachment_cleanup_at',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value", [$now + 86400]);
        });
    }

    public function markAttachmentSent(int $id, int $now): void
    {
        $this->run('UPDATE attachments SET first_sent_at=COALESCE(first_sent_at,?) WHERE id=?', [$now, $id]);
    }

    public function expiredAttachments(int $now): array
    {
        return $this->transaction(function () use ($now): array {
            $cutoff = $now - 2_592_000;
            $this->run("UPDATE jobs SET status='failed',error='attachment_expired' WHERE kind='delivery' AND status='pending' AND broadcast_id IN (SELECT b.broadcast_id FROM broadcast_attachments b JOIN attachments a ON a.id=b.attachment_id WHERE a.first_sent_at<=?)", [$cutoff]);
            return $this->run("SELECT a.* FROM attachments a WHERE a.status='ready' AND ((a.first_sent_at IS NOT NULL AND a.first_sent_at<=?) OR (a.first_sent_at IS NULL AND a.created_at<=? AND NOT EXISTS (SELECT 1 FROM broadcast_attachments b WHERE b.attachment_id=a.id) AND NOT EXISTS (SELECT 1 FROM draft_attachments da JOIN drafts d ON d.id=da.draft_id WHERE da.attachment_id=a.id AND d.updated_at>?))) AND NOT EXISTS (SELECT 1 FROM broadcast_attachments b JOIN jobs j ON j.broadcast_id=b.broadcast_id WHERE b.attachment_id=a.id AND j.status IN ('pending','sending'))", [$cutoff, $cutoff, $cutoff])->fetchAll();
        });
    }

    public function markAttachmentExpired(int $id): void { $this->run("UPDATE attachments SET status='expired' WHERE id=?", [$id]); }

    public function clearUnsentAttachmentStart(int $id, int $now): void
    {
        $this->run('UPDATE attachments SET first_sent_at=NULL WHERE id=? AND first_sent_at=?', [$id, $now]);
    }

    public function completeAttachment(int $id, \Broadcast\Domain\Attachment $attachment): void
    {
        $this->run("UPDATE attachments SET status='ready',metadata=? WHERE id=? AND status='pending'", [json_encode($attachment, JSON_THROW_ON_ERROR), $id]);
    }

    public function draftReady(int $id): bool
    {
        $draft = $this->draft($id);
        if (!$draft || trim($draft['subject']) === '') { return false; }
        $files = $this->draftAttachments($id);
        if (trim($draft['text']) === '' && $files === []) { return false; }
        foreach ($files as $file) { if ($file['status'] !== 'ready') { return false; } }
        return true;
    }

    public function queueDraftPreview(int $actor, int $draftId, int $version, string $text, array $options): void
    {
        if ($this->one("SELECT id FROM jobs WHERE kind='draft_preview' AND status='pending' AND user_id=?
            AND json_extract(payload,'$.draft_id')=CAST(? AS INTEGER) AND json_extract(payload,'$.version')=CAST(? AS INTEGER)", [$actor, $draftId, $version])) { return; }
        $this->job('draft_preview', null, $actor, null, ['draft_id' => $draftId, 'version' => $version, 'text' => $text, 'options' => $options, 'snapshot_id' => bin2hex(random_bytes(16))]);
    }

    public function draftAudienceCounts(int $adminId, ?\Broadcast\Domain\RecipientSnapshot $snapshot = null): array
    {
        $counts = ['telegram' => 0, 'whatsapp' => 0, 'email' => 0];
        if ($snapshot !== null) {
            foreach ($snapshot->recipients as $recipient) {
                if (($recipient['telegram_user_id'] ?? null) === $adminId || $this->directoryBlocked($recipient['contact_id'])) { continue; }
                ++$counts[$recipient['channel']];
            }
            return $counts;
        }
        $eligible = "u.status='approved' AND NOT EXISTS (SELECT 1 FROM channel_identities author WHERE author.user_id=u.id AND author.channel='telegram' AND author.address=? AND author.retired=0)";
        foreach ($this->run("SELECT i.channel,COUNT(*) AS total FROM channel_identities i JOIN users u ON u.id=i.user_id WHERE $eligible AND i.enabled=1 AND i.retired=0 AND (i.channel!='telegram' OR u.subscribed=1) GROUP BY i.channel", [(string) $adminId])->fetchAll() as $row) { $counts[$row['channel']] = (int) $row['total']; }
        $counts['email'] = (int) $this->run("SELECT COUNT(*) FROM users u WHERE $eligible AND trim(COALESCE(u.email,''))!=''", [(string) $adminId])->fetchColumn();
        return $counts;
    }

    public function markDraftPreviewed(int $draftId, int $version, int $actor): void
    {
        $draft = $this->draft($draftId);
        if ($draft && $draft['version'] === $version && $this->draftReady($draftId)) {
            $this->run('INSERT OR IGNORE INTO draft_previews VALUES (?,?,?)', [$draftId, $version, $actor]);
        }
    }

    public function draftPreviewed(int $draftId, int $version, int $actor): bool
    {
        return $this->one('SELECT 1 FROM draft_previews WHERE draft_id=? AND version=? AND actor=?', [$draftId, $version, $actor]) !== null;
    }

    public function draft(int $id): ?array { return $this->one('SELECT * FROM drafts WHERE id=?', [$id]); }

    public function saveDraft(int $id, int $version, array $changes): bool
    {
        $set = [];
        $values = [];
        foreach (['text', 'subject', 'status'] as $field) {
            if (array_key_exists($field, $changes)) { $set[] = "$field=?"; $values[] = $changes[$field]; }
        }
        if (!$set) { return false; }
        $values[] = $id;
        $values[] = $version;
        $updated = $this->run('UPDATE drafts SET ' . implode(',', $set) . ",updated_at=CAST(strftime('%s','now') AS INTEGER),version=version+1 WHERE id=? AND version=? AND status!='approved'", $values)->rowCount() === 1;
        if ($updated && $changes === ['status' => 'pending']) {
            $this->run('INSERT OR IGNORE INTO draft_previews(draft_id,version,actor) SELECT draft_id,?,actor FROM draft_previews WHERE draft_id=? AND version=?', [$version + 1, $id, $version]);
        }
        return $updated;
    }

    public function approveDraft(int $id, int $version, int $actor): ?array
    {
        $draft = $this->draft($id);
        if (!$draft || $draft['status'] !== 'pending' || $draft['version'] !== $version || !$this->draftReady($id)) { return null; }
        if ($this->draftAttachments($id) !== [] && !$this->draftPreviewed($id, $version, $actor)) { return null; }
        $broadcast = $this->createBroadcast($draft['admin_id'], $draft['text']);
        $this->run('UPDATE broadcasts SET subject=?,approved_by=?,team_channel_id=? WHERE id=?', [$draft['subject'], $actor, $this->teamChannelId, $broadcast['id']]);
        $this->run("UPDATE drafts SET status='approved',version=version+1,broadcast_id=? WHERE id=?", [$broadcast['id'], $id]);
        $this->run('INSERT INTO broadcast_attachments(broadcast_id,attachment_id,position) SELECT ?,attachment_id,position FROM draft_attachments WHERE draft_id=?', [$broadcast['id'], $id]);
        if ($this->teamChannelId !== null) {
            // Channel-only translations must not become dependencies of user deliveries.
            $this->job('translate', $broadcast['id'], null, 'RU');
            $this->job('translate_subject', $broadcast['id'], null, 'RU');
            $this->job('channel_post', $broadcast['id'], $this->teamChannelId, 'RU', channel: 'team');
        }
        return $broadcast;
    }

    public function queueComment(int $userId, string $language, string $text, string $prefix, int $staffId, array $options = []): void
    {
        foreach ($this->identities($userId) as $identity) {
            $wa = $identity['channel'] === 'whatsapp';
            $suffix = $wa ? $this->whatsappMenu($identity['id'], '', $options) : '';
            $this->job('comment', null, $userId, $language, ['text' => $text, 'prefix' => $prefix, 'staff_id' => $staffId, 'options' => $wa ? [] : $options, 'menu_suffix' => $suffix, 'address' => $identity['address'], 'identity_id' => $identity['id']], channel: $identity['channel']);
        }
    }

    public function queueExport(int $chatId): void { $this->job('export', null, $chatId, null, []); }

    public function exportUsers(): array
    {
        return $this->run("SELECT u.*,tg.address AS telegram_id,wa.address AS whatsapp_id,wa.verified_phone AS whatsapp_verified_phone
            FROM users u
            LEFT JOIN channel_identities tg ON tg.user_id=u.id AND tg.channel='telegram' AND tg.retired=0
            LEFT JOIN channel_identities wa ON wa.user_id=u.id AND wa.channel='whatsapp' AND wa.retired=0
            WHERE u.submitted_at IS NOT NULL ORDER BY u.submitted_at,u.id")->fetchAll();
    }

    public function retryBroadcast(int $id): bool
    {
        $broadcast = $this->broadcast($id);
        if ($broadcast === null || !$broadcast['approved_by']) { return false; }
        $changed = $this->run("UPDATE jobs SET status='pending', attempts=0, next_at=0, error=NULL WHERE broadcast_id=? AND status='failed'", [$id])->rowCount();
        if ($changed === 0) { return false; }
        $this->run("UPDATE broadcasts SET status=CASE WHEN status='failed' THEN 'preparing' ELSE status END, reported=0 WHERE id=?", [$id]);
        return true;
    }

    public function nextJob(int $now): ?array
    {
        // Network calls run outside transactions. The process lock guarantees one worker.
        $job = $this->one("SELECT j.* FROM jobs j LEFT JOIN broadcasts b ON b.id=j.broadcast_id
            WHERE j.status='pending' AND json_extract(j.payload,'$.notice_key') IS NULL AND j.next_at<=? AND (j.channel!='whatsapp' OR (
                (EXISTS (SELECT 1 FROM channel_states WHERE channel='whatsapp' AND state='connected') OR COALESCE((SELECT next_probe_at FROM channel_states WHERE channel='whatsapp'),0)<=CAST(? AS INTEGER))
                AND (j.kind!='delivery' OR NOT EXISTS (SELECT 1 FROM jobs earlier WHERE earlier.channel='whatsapp' AND earlier.kind='delivery' AND earlier.status IN ('pending','sending') AND (earlier.broadcast_id<j.broadcast_id OR (earlier.broadcast_id=j.broadcast_id AND earlier.id<j.id))))
            )) AND (
                j.kind IN ('message','photo','callback','translate','comment','export','directory_sync','attachment_download','edit_keyboard','attachment_notice','attachment_cleanup') OR
                (j.kind='draft_preview' AND NOT EXISTS (SELECT 1 FROM jobs notice WHERE notice.kind='attachment_notice' AND notice.status='pending' AND json_extract(notice.payload,'$.draft_id')=json_extract(j.payload,'$.draft_id'))) OR
                (j.kind IN ('audience_snapshot','directory_catchup') AND NOT EXISTS (SELECT 1 FROM jobs ds WHERE ds.kind='directory_sync' AND ds.status!='done')) OR
                (j.kind='translate_subject' AND EXISTS (SELECT 1 FROM translations t WHERE t.broadcast_id=j.broadcast_id AND t.language=j.language)) OR
                (j.kind='channel_post' AND b.approved_by>0 AND b.team_channel_id=j.user_id AND EXISTS (
                    SELECT 1 FROM translations t WHERE t.broadcast_id=j.broadcast_id AND t.language='RU' AND t.subject IS NOT NULL)) OR
                (j.kind='delivery' AND b.status='ready' AND EXISTS (
                    SELECT 1 FROM translations t WHERE t.broadcast_id=j.broadcast_id AND t.language=j.language AND t.subject IS NOT NULL)))
            ORDER BY CASE j.kind WHEN 'callback' THEN 0 WHEN 'message' THEN 1 WHEN 'photo' THEN 1 WHEN 'translate' THEN 2 WHEN 'translate_subject' THEN 2 ELSE 3 END, j.id LIMIT 1", [$now, $now]);
        if ($job !== null) { $job['payload'] = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR); }
        return $job;
    }

    public function completeJob(int $id, string $status = 'done'): void { $this->run('UPDATE jobs SET status=?,error=NULL WHERE id=?', [$status, $id]); }
    public function advancePart(int $id): void { $this->run("UPDATE jobs SET part=part+1,attempts=0,next_at=0,status='pending' WHERE id=? AND status IN ('pending','sending')", [$id]); }

    public function beginDeliveryPart(int $jobId): bool
    {
        return $this->run("UPDATE jobs SET status='sending' WHERE id=? AND kind='delivery' AND channel IN ('telegram','email') AND status='pending'", [$jobId])->rowCount() === 1;
    }

    public function recoverDeliveryParts(): array
    {
        return $this->transaction(function (): array {
            $ids = $this->run("SELECT id FROM jobs WHERE kind='delivery' AND channel IN ('telegram','email') AND status='sending'")->fetchAll(PDO::FETCH_COLUMN);
            $this->run("UPDATE jobs SET status='unknown',error='delivery_uncertain' WHERE kind='delivery' AND channel IN ('telegram','email') AND status='sending'");
            return $ids;
        });
    }

    public function deferJob(int $id, int $attempts, int $nextAt, string $error, bool $failed): void
    {
        $this->transaction(function () use ($id, $attempts, $nextAt, $error, $failed): void {
            $changed = $this->run("UPDATE jobs SET attempts=?,next_at=?,error=?,status=?,payload=json_remove(payload,'$.checked_part','$.checked_at','$.checked_epoch') WHERE id=? AND status NOT IN ('unknown','accepted','delivered','read')", [$attempts, $nextAt, $error, $failed ? 'failed' : 'pending', $id])->rowCount();
            if ($changed > 0) {
                // Called only for errors that positively establish that no send occurred.
                $this->run("UPDATE whatsapp_parts SET status=? WHERE job_id=? AND status='sending'", [$failed ? 'failed' : 'pending', $id]);
            }
        });
    }

    public function translation(int $broadcastId, string $language): ?string
    {
        return $this->one('SELECT text FROM translations WHERE broadcast_id=? AND language=?', [$broadcastId, $language])['text'] ?? null;
    }

    public function saveTranslation(int $broadcastId, string $language, string $text): void
    {
        $this->run('INSERT INTO translations(broadcast_id,language,text) VALUES (?,?,?) ON CONFLICT DO NOTHING', [$broadcastId, $language, $text]);
    }

    public function translationSubject(int $id, string $language): ?string
    {
        return $this->one('SELECT subject FROM translations WHERE broadcast_id=? AND language=?', [$id, $language])['subject'] ?? null;
    }

    public function saveTranslationSubject(int $id, string $language, string $subject): void
    {
        $this->run('UPDATE translations SET subject=? WHERE broadcast_id=? AND language=?', [$subject, $id, $language]);
    }

    public function updateJobPayload(int $id, array $payload): void
    {
        $this->run('UPDATE jobs SET payload=? WHERE id=?', [json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
    }

    public function mailNextAt(): int
    {
        return (int) ($this->one("SELECT value FROM runtime_state WHERE name='mail_next_at'")['value'] ?? 0);
    }

    public function setMailNextAt(int $time): void
    {
        $this->run("INSERT INTO runtime_state(name,value) VALUES ('mail_next_at',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value", [$time]);
    }

    public function channelState(string $channel): array
    {
        return $this->one('SELECT state,next_probe_at FROM channel_states WHERE channel=?', [$channel]) ?? ['state' => 'unknown', 'next_probe_at' => 0];
    }

    public function setChannelState(string $channel, string $state, int $nextProbeAt): bool
    {
        if (!in_array($state, ['unknown', 'connected', 'unavailable'], true) || $nextProbeAt < 0) { throw new \InvalidArgumentException('Invalid channel state.'); }
        return $this->transaction(function () use ($channel, $state, $nextProbeAt): bool {
            $changed = $this->channelState($channel)['state'] !== $state;
            $this->run('INSERT INTO channel_states(channel,state,next_probe_at) VALUES (?,?,?) ON CONFLICT(channel) DO UPDATE SET state=excluded.state,next_probe_at=excluded.next_probe_at', [$channel, $state, $nextProbeAt]);
            return $changed;
        });
    }

    public function beginWhatsAppPart(int $jobId, int $part, int $now): bool
    {
        return $this->transaction(function () use ($jobId, $part, $now): bool {
            $job = $this->one("SELECT * FROM jobs WHERE id=? AND channel='whatsapp' AND status='pending' AND part=?", [$jobId, $part]);
            if ($job === null || $part < 0) { return false; }
            $payload = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR);
            if (!isset($payload['address']) || !is_string($payload['address']) || $payload['address'] === '') { return false; }
            $previous = $this->one('SELECT status FROM whatsapp_parts WHERE job_id=? AND part=?', [$jobId, $part]);
            if ($previous !== null && !in_array($previous['status'], ['pending', 'failed'], true)) { return false; }
            $this->run("INSERT INTO whatsapp_parts(job_id,part,address,status,started_at) VALUES (?,?,?,'sending',?) ON CONFLICT(job_id,part) DO UPDATE SET status='sending',started_at=excluded.started_at", [$jobId, $part, $payload['address'], $now]);
            $this->run("UPDATE jobs SET status='sending' WHERE id=?", [$jobId]);
            return true;
        });
    }

    public function completeWhatsAppPart(int $jobId, int $part, string $messageId, bool $last): void
    {
        if ($messageId === '' || strlen($messageId) > 256) { throw new \InvalidArgumentException('Invalid transport message ID.'); }
        $this->transaction(function () use ($jobId, $part, $messageId, $last): void {
            $intent = $this->one("SELECT p.* FROM whatsapp_parts p JOIN jobs j ON j.id=p.job_id WHERE p.job_id=? AND p.part=? AND p.status='sending' AND j.status='sending' AND j.part=p.part", [$jobId, $part]);
            if ($intent === null) { return; }
            $receipt = $this->one('SELECT status FROM whatsapp_receipts WHERE message_id=? AND address=?', [$messageId, $intent['address']]);
            $this->run('UPDATE whatsapp_parts SET message_id=?,status=?,is_last=? WHERE job_id=? AND part=?', [$messageId, $receipt['status'] ?? 'accepted', (int) $last, $jobId, $part]);
            $this->run('UPDATE jobs SET status=?,part=part+1,attempts=0,next_at=0,error=NULL WHERE id=?', [$last ? 'accepted' : 'pending', $jobId]);
            $this->aggregateWhatsAppParts($jobId);
        });
    }

    public function markWhatsAppUncertain(int $jobId, int $part): void
    {
        $this->transaction(function () use ($jobId, $part): void {
            $changed = $this->run("UPDATE whatsapp_parts SET status='unknown' WHERE job_id=? AND part=? AND status='sending'", [$jobId, $part])->rowCount();
            if ($changed > 0) { $this->run("UPDATE jobs SET status='unknown',error='whatsapp_uncertain' WHERE id=? AND status='sending'", [$jobId]); }
        });
    }

    public function recoverWhatsAppDispatches(): array
    {
        return $this->transaction(function (): array {
            $ids = $this->run("SELECT id FROM jobs WHERE channel='whatsapp' AND status='sending' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            $this->run("UPDATE whatsapp_parts SET status='unknown' WHERE status='sending'");
            $this->run("UPDATE jobs SET status='unknown',error='whatsapp_uncertain' WHERE channel='whatsapp' AND status='sending'");
            return array_map('intval', $ids);
        });
    }

    public function recordWhatsAppReceipt(string $messageId, string $status, string $address): bool
    {
        if ($messageId === '' || strlen($messageId) > 256 || !in_array($status, ['accepted', 'delivered', 'read'], true)
            || !preg_match('/^[1-9][0-9]{6,14}$/D', $address)) { return false; }
        return $this->transaction(function () use ($messageId, $status, $address): bool {
            $previous = $this->one('SELECT status FROM whatsapp_receipts WHERE message_id=? AND address=?', [$messageId, $address]);
            $ranks = ['accepted' => 0, 'delivered' => 1, 'read' => 2];
            if ($previous !== null && $ranks[$previous['status']] >= $ranks[$status]) { return false; }
            $this->run('INSERT INTO whatsapp_receipts(message_id,address,status) VALUES (?,?,?) ON CONFLICT(message_id,address) DO UPDATE SET status=excluded.status', [$messageId, $address, $status]);
            // A receipt is evidence only for its exact message and snapshotted destination.
            $part = $this->one('SELECT job_id,part,status FROM whatsapp_parts WHERE message_id=? AND address=?', [$messageId, $address]);
            if ($part !== null && isset($ranks[$part['status']]) && $ranks[$status] > $ranks[$part['status']]) {
                $this->run('UPDATE whatsapp_parts SET status=? WHERE job_id=? AND part=?', [$status, $part['job_id'], $part['part']]);
                $this->aggregateWhatsAppParts($part['job_id']);
            }
            return true;
        });
    }

    private function aggregateWhatsAppParts(int $jobId): void
    {
        $parts = $this->run('SELECT part,status,is_last FROM whatsapp_parts WHERE job_id=? ORDER BY part', [$jobId])->fetchAll();
        if ($parts === [] || !$parts[array_key_last($parts)]['is_last']) { return; }
        $rank = 2;
        $ranks = ['accepted' => 0, 'delivered' => 1, 'read' => 2];
        foreach ($parts as $index => $part) {
            if ($part['part'] !== $index || !isset($ranks[$part['status']])) { return; }
            $rank = min($rank, $ranks[$part['status']]);
        }
        $this->run("UPDATE jobs SET status=? WHERE id=? AND status IN ('accepted','delivered','read')", [array_keys($ranks)[$rank], $jobId]);
    }

    public function deferWhatsAppReceipt(string $eventId, int $sequence, array $messageIds, string $status, string $address): void
    {
        if ($eventId === '' || strlen($eventId) > 256 || $sequence < 0 || $messageIds === [] || count($messageIds) > 1000
            || !in_array($status, ['accepted', 'delivered', 'read'], true) || !preg_match('/^[1-9][0-9]{0,19}(?:@lid)?$/D', $address)) { throw new \InvalidArgumentException('Invalid deferred receipt.'); }
        foreach ($messageIds as $messageId) {
            if (!is_string($messageId) || $messageId === '' || strlen($messageId) > 256) { throw new \InvalidArgumentException('Invalid deferred message ID.'); }
        }
        $this->run('INSERT OR IGNORE INTO whatsapp_pending_receipts(event_id,sequence,message_ids,status,address) VALUES (?,?,?,?,?)', [$eventId, $sequence, json_encode(array_values(array_unique($messageIds)), JSON_THROW_ON_ERROR), $status, $address]);
    }

    public function pendingWhatsAppReceipts(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        return $this->transaction(function () use ($limit): array {
            $rows = $this->run('SELECT event_id,sequence,message_ids,status,address FROM whatsapp_pending_receipts ORDER BY last_selected,sequence,event_id LIMIT ' . $limit)->fetchAll();
            $turn = (int) $this->db->query('SELECT COALESCE(MAX(last_selected),0) FROM whatsapp_pending_receipts')->fetchColumn();
            foreach ($rows as &$row) {
                $row['message_ids'] = json_decode($row['message_ids'], true, flags: JSON_THROW_ON_ERROR);
                $this->run('UPDATE whatsapp_pending_receipts SET last_selected=? WHERE event_id=?', [++$turn, $row['event_id']]);
            }
            return $rows;
        });
    }

    public function completeWhatsAppReceipt(string $eventId): void
    {
        $this->run('DELETE FROM whatsapp_pending_receipts WHERE event_id=?', [$eventId]);
    }

    public function retryAuxiliaryJob(int $jobId, int $actor, bool $superadmin, bool $allowGeneral = true): bool
    {
        $job = $this->one("SELECT * FROM jobs WHERE id=? AND kind IN ('comment','export','attachment_download','draft_preview','directory_sync','directory_catchup') AND status='failed'", [$jobId]);
        if (!$job) { return false; }
        if (!$allowGeneral && !in_array($job['kind'], ['attachment_download','draft_preview'], true)) { return false; }
        if (in_array($job['kind'], ['directory_sync','directory_catchup'], true) && !$superadmin) { return false; }
        $payload = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR);
        $owner = $payload['staff_id'] ?? $job['user_id'];
        if (!$superadmin && $owner !== $actor) { return false; }
        return $this->run("UPDATE jobs SET status='pending',attempts=0,next_at=0,error=NULL WHERE id=? AND status='failed'", [$jobId])->rowCount() === 1;
    }

    public function disableSubscriber(int $id): void
    {
        $this->run('UPDATE users SET subscribed=0 WHERE id=?', [$id]);
        $this->run("UPDATE channel_identities SET enabled=0 WHERE user_id=? AND channel='telegram'", [$id]);
        $this->syncDirectoryUser($id);
    }

    public function refreshBroadcasts(): void
    {
        $this->transaction(function (): void {
            $this->db->exec("UPDATE broadcasts SET status='failed' WHERE status='preparing' AND EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=broadcasts.id AND kind IN ('translate','translate_subject') AND initial=1 AND status='failed')");
            $this->db->exec("UPDATE jobs SET status='failed',error='translation_failed' WHERE kind='delivery' AND status='pending' AND (EXISTS (SELECT 1 FROM broadcasts WHERE id=jobs.broadcast_id AND status='failed') OR EXISTS (SELECT 1 FROM jobs t WHERE t.broadcast_id=jobs.broadcast_id AND t.language=jobs.language AND t.kind IN ('translate','translate_subject') AND t.status='failed'))");
            $this->db->exec("UPDATE jobs SET status='failed',error='translation_failed' WHERE kind='translate_subject' AND status='pending' AND EXISTS (SELECT 1 FROM jobs t WHERE t.broadcast_id=jobs.broadcast_id AND t.language=jobs.language AND t.kind='translate' AND t.status='failed')");
            $this->db->exec("UPDATE jobs SET status='failed',error='translation_failed' WHERE kind='channel_post' AND status='pending' AND EXISTS (SELECT 1 FROM jobs t WHERE t.broadcast_id=jobs.broadcast_id AND t.language='RU' AND t.kind IN ('translate','translate_subject') AND t.status='failed')");
            $this->db->exec("UPDATE broadcasts SET status='ready' WHERE status='preparing' AND NOT EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=broadcasts.id AND kind IN ('translate','translate_subject') AND initial=1 AND status!='done')");
            $finished = $this->run("SELECT * FROM broadcasts b WHERE reported=0 AND status IN ('ready','failed') AND NOT EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=b.id AND report_included=1 AND status IN ('pending','sending'))")->fetchAll();
            foreach ($finished as $broadcast) {
                $report = Messages::text('RU', 'broadcast_report', ['id' => $broadcast['id']]);
                foreach (['telegram', 'email'] as $channel) {
                    $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0, 'unknown' => 0];
                    foreach ($this->run("SELECT status,COUNT(*) AS total FROM jobs WHERE broadcast_id=? AND kind='delivery' AND report_included=1 AND channel=? GROUP BY status", [$broadcast['id'], $channel])->fetchAll() as $row) {
                        $counts[$row['status']] = (int) $row['total'];
                    }
                    $report .= "\n" . Messages::text('RU', 'broadcast_report_' . $channel, $counts);
                    if ($counts['unknown'] > 0) { $report .= "\n" . Messages::text('RU', 'attachment_report_unknown', ['channel' => $channel, 'count' => $counts['unknown']]); }
                }
                $wa = $this->run("SELECT status,COUNT(*) AS total FROM jobs WHERE broadcast_id=? AND kind='delivery' AND report_included=1 AND channel='whatsapp' GROUP BY status", [$broadcast['id']])->fetchAll();
                if ($wa !== []) {
                    $counts = ['accepted' => 0, 'delivered' => 0, 'read' => 0, 'unknown' => 0, 'skipped' => 0, 'failed' => 0];
                    foreach ($wa as $row) { $counts[$row['status']] = (int) $row['total']; }
                    $report .= "\n" . Messages::text('RU', 'broadcast_report_whatsapp', $counts);
                }
                if ($broadcast['team_channel_id'] !== null) {
                    $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0, 'unknown' => 0];
                    foreach ($this->run("SELECT status,COUNT(*) AS total FROM jobs WHERE broadcast_id=? AND kind='channel_post' AND report_included=1 GROUP BY status", [$broadcast['id']])->fetchAll() as $row) {
                        $counts[$row['status']] = (int) $row['total'];
                    }
                    $report .= "\n" . Messages::text('RU', 'broadcast_report_team', $counts);
                    if ($counts['unknown'] > 0) { $report .= "\n" . Messages::text('RU', 'broadcast_report_team_unknown', $counts); }
                }
                if ($broadcast['approved_by']) {
                    if ($this->broadcastAttachments($broadcast['id']) !== []) {
                        foreach ($this->run("SELECT channel,COUNT(*) AS total FROM jobs WHERE broadcast_id=? AND kind='delivery' AND part>0 AND status IN ('failed','skipped','unknown') GROUP BY channel", [$broadcast['id']])->fetchAll() as $partial) {
                            $report .= "\n" . Messages::text('RU', 'attachment_report_partial', ['channel' => $partial['channel'], 'count' => $partial['total']]);
                        }
                    }
                    $this->queueReply($broadcast['approved_by'], $report);
                }
                $this->run('UPDATE broadcasts SET reported=1 WHERE id=?', [$broadcast['id']]);
            }
        });
    }

    private function job(string $kind, ?int $broadcastId, ?int $userId, ?string $language, array $payload = [], bool $initial = false, string $channel = 'telegram'): bool
    {
        // User deliveries and the independent channel post both wait in the report.
        return $this->run('INSERT OR IGNORE INTO jobs(kind,broadcast_id,user_id,language,payload,initial,report_included,channel) VALUES (?,?,?,?,?,?,?,?)', [$kind, $broadcastId, $userId, $language, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (int) $initial, (int) ($initial || in_array($kind, ['delivery', 'channel_post'], true)), $channel])->rowCount() === 1;
    }

    private function run(string $sql, array $values = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    private function one(string $sql, array $values = []): ?array
    {
        $row = $this->run($sql, $values)->fetch();
        return $row === false ? null : $row;
    }
}
