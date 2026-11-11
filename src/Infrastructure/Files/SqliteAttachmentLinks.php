<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Files;

use Broadcast\Application\AttachmentLinks;
use Broadcast\Application\AttachmentStorage;
use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;
use PDO;

final class SqliteAttachmentLinks implements AttachmentLinks
{
    private const TTL = 2_592_000;
    private PDO $db;
    private string $baseUrl;
    private ?string $keyPath = null;
    private ?string $key = null;
    private static ?\WeakMap $memoryKeys = null;

    public function __construct(PDO|string $database, private AttachmentStorage $storage, string $publicBaseUrl)
    {
        $url = parse_url($publicBaseUrl);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') === ''
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $publicBaseUrl)) {
            throw new \InvalidArgumentException('Invalid attachment URL configuration.');
        }
        $this->baseUrl = rtrim($publicBaseUrl, '/');
        try {
            if (is_string($database) && (!is_file($database) || is_link($database))) { throw new \RuntimeException(); }
            $this->db = $database instanceof PDO ? $database : new PDO('sqlite:' . $database);
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { throw new \RuntimeException(); }
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->exec('PRAGMA busy_timeout=5000');
            foreach ($this->db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                if ($entry['name'] === 'main' && $entry['file'] !== '') { $this->keyPath = $entry['file'] . '.attachment-links.key'; }
            }
        } catch (\Throwable) {
            throw new ApiFailure('Attachment link storage unavailable.', true);
        }
    }

    public function issue(Attachment $attachment, int $contactId, int $now): string
    {
        if ($contactId < 1 || $now < 0 || $now > PHP_INT_MAX - self::TTL) {
            throw new \InvalidArgumentException('Invalid attachment link parameters.');
        }
        $ownedTransaction = false;
        try {
            $this->storage->path($attachment->storageKey);
            $key = $this->signingKey();
            if ($this->db->inTransaction()) { throw new \RuntimeException(); }
            $this->db->beginTransaction();
            $ownedTransaction = true;
            $insert = $this->db->prepare('INSERT OR IGNORE INTO attachment_link_files(storage_key,display_name,mime,byte_size,sha256,first_issued_at,expires_at) VALUES (?,?,?,?,?,?,?)');
            $insert->execute([$attachment->storageKey,$attachment->displayName,$attachment->mime,$attachment->byteSize,$attachment->sha256,$now,$now+self::TTL]);
            $select = $this->db->prepare('SELECT * FROM attachment_link_files WHERE storage_key=?');
            $select->execute([$attachment->storageKey]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['expires_at'] <= $now || $row['display_name'] !== $attachment->displayName
                || $row['mime'] !== $attachment->mime || (int)$row['byte_size'] !== $attachment->byteSize || $row['sha256'] !== $attachment->sha256) {
                throw new ApiFailure('Attachment link unavailable.');
            }
            // Retry URLs are reproducible only with the secret outside SQLite; jobs retain no bearer token.
            $context = implode("\0", [$this->baseUrl, $attachment->storageKey, (string) $contactId, (string) $row['expires_at']]);
            $token = rtrim(strtr(base64_encode(hash_hmac('sha256', $context, $key, true)), '+/', '-_'), '=');
            $grant = $this->db->prepare('INSERT OR IGNORE INTO attachment_links(token_hash,storage_key,contact_id,created_at,expires_at) VALUES (?,?,?,?,?)');
            $grant->execute([hash('sha256',$token),$attachment->storageKey,$contactId,$now,(int)$row['expires_at']]);
            $this->db->commit();
            return $this->baseUrl . '/' . $token;
        } catch (\Throwable $failure) {
            if ($ownedTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            if ($failure instanceof ApiFailure) { throw $failure; }
            throw new ApiFailure('Attachment link creation failed.', true);
        }
    }

    private function signingKey(): string
    {
        if ($this->key !== null) { return $this->key; }
        if ($this->keyPath === null) {
            self::$memoryKeys ??= new \WeakMap();
            return $this->key = self::$memoryKeys[$this->db] ??= random_bytes(32);
        }
        if (is_link($this->keyPath)) { throw new ApiFailure('Attachment link key unavailable.'); }
        $mayCreate = (int) $this->db->query('SELECT COUNT(*) FROM attachment_link_files')->fetchColumn() === 0;
        if (!is_file($this->keyPath) && !$mayCreate) { throw new ApiFailure('Attachment link key unavailable.'); }
        $stream = null;
        $oldMask = umask(0077);
        try {
            $stream = fopen($this->keyPath, 'c+b');
            if ($stream === false || !flock($stream, LOCK_EX) || !chmod($this->keyPath, 0600)) { throw new \RuntimeException(); }
            $key = stream_get_contents($stream, 33);
            if ($key === '' && $mayCreate) {
                $key = random_bytes(32);
                if (fwrite($stream, $key) !== 32 || !fflush($stream)) { throw new \RuntimeException(); }
            }
            if (!is_string($key) || strlen($key) !== 32) { throw new \RuntimeException(); }
            return $this->key = $key;
        } catch (\Throwable) {
            throw new ApiFailure('Attachment link key unavailable.');
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            umask($oldMask);
        }
    }

    public function resolve(string $token, int $now): ?Attachment
    {
        if ($now < 0 || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) { return null; }
        try {
            $query = $this->db->prepare('SELECT f.* FROM attachment_links l JOIN attachment_link_files f ON f.storage_key=l.storage_key WHERE l.token_hash=? AND l.expires_at>? AND f.expires_at>?');
            $query->execute([hash('sha256',$token),$now,$now]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!$row) { return null; }
            $attachment = new Attachment($row['storage_key'],$row['display_name'],$row['mime'],(int)$row['byte_size'],$row['sha256']);
            $this->storage->path($attachment->storageKey);
            return $attachment;
        } catch (\Throwable) {
            return null;
        }
    }

    public function cleanup(int $now): int
    {
        if ($now < 0) { throw new \InvalidArgumentException('Invalid cleanup time.'); }
        try {
            $query = $this->db->prepare('DELETE FROM attachment_links WHERE expires_at<=?');
            $query->execute([$now]);
            // Retain the original expiry even when the last grant disappears.
            return $query->rowCount();
        } catch (\Throwable) {
            throw new ApiFailure('Attachment link cleanup failed.', true);
        }
    }
}
