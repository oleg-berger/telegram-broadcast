<?php
declare(strict_types=1);

namespace Broadcast\Tests\Files;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Files\LocalAttachmentStorage;
use Broadcast\Infrastructure\Files\SqliteAttachmentLinks;
use PHPUnit\Framework\TestCase;

final class AttachmentLinksTest extends TestCase
{
    private string $directory;
    private \PDO $db;
    private LocalAttachmentStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/link-test-' . bin2hex(random_bytes(8));
        $this->storage = new LocalAttachmentStorage($this->directory);
        $this->db = new \PDO('sqlite::memory:');
        $migration = dirname(__DIR__, 2) . '/migrations/008_attachment_links.sql';
        if (is_file($migration)) { $this->db->exec(file_get_contents($migration)); }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) { unlink($file); }
        rmdir($this->directory);
    }

    private function fixture(): \Broadcast\Domain\Attachment
    {
        $source = $this->directory . '/source'; file_put_contents($source, 'shared document');
        $file = $this->storage->put($source, 'Прайс.pdf'); unlink($source);
        return $file;
    }

    public function testRestartReusesUrlWithoutStoringRecoverableTokenInDatabase(): void
    {
        $path = $this->directory . '/links.sqlite';
        $db = new \PDO('sqlite:' . $path);
        $db->exec(file_get_contents(dirname(__DIR__, 2) . '/migrations/008_attachment_links.sql'));
        $file = $this->fixture();
        $first = (new SqliteAttachmentLinks($path, $this->storage, 'https://files.example.com/download'))->issue($file, 42, 100);
        $second = (new SqliteAttachmentLinks($path, $this->storage, 'https://files.example.com/download'))->issue($file, 42, 200);
        self::assertSame($first, $second);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM attachment_links')->fetchColumn());
        self::assertStringNotContainsString(basename($first), file_get_contents($path));
        self::assertFileExists($path . '.attachment-links.key');
        self::assertSame(32, filesize($path . '.attachment-links.key'));
        if (PHP_OS_FAMILY !== 'Windows') { self::assertSame(0600, fileperms($path . '.attachment-links.key') & 0777); }
        unlink($path . '.attachment-links.key');
        $resolver = new SqliteAttachmentLinks($path, $this->storage, 'https://files.example.com/download');
        self::assertEquals($file, $resolver->resolve(basename($first), 201));
        try { $resolver->issue($file, 42, 201); self::fail('Lost signing key was silently replaced.'); }
        catch (ApiFailure) { self::assertFileDoesNotExist($path . '.attachment-links.key'); }
    }

    public function testSigningKeyCanStayOutsideDatabaseAndDownloadVolume(): void
    {
        $path = $this->directory . '/reader.sqlite';
        $db = new \PDO('sqlite:' . $path);
        $db->exec(file_get_contents(dirname(__DIR__, 2) . '/migrations/008_attachment_links.sql'));
        $file = $this->fixture();
        $keyPath = $this->directory . '/bot-only.key';
        $issuer = new SqliteAttachmentLinks($path, $this->storage, 'https://files.example.com/download', $keyPath);
        $url = $issuer->issue($file, 42, 100);
        self::assertFileExists($keyPath);
        self::assertFileDoesNotExist($path . '.attachment-links.key');
        self::assertSame($url, (new SqliteAttachmentLinks($path, $this->storage, 'https://files.example.com/download', $keyPath))->issue($file,42,101));
        self::assertEquals($file, (new SqliteAttachmentLinks($path, $this->storage, 'https://files.example.com/download'))->resolve(basename($url),101));
    }

    public function testLinkWorksUntilExactExpiryAndDatabaseContainsOnlyTokenHash(): void
    {
        $links = new SqliteAttachmentLinks($this->db, $this->storage, 'https://files.example.com/download');
        $file = $this->fixture();
        $url = $links->issue($file, 42, 100);
        self::assertMatchesRegularExpression('~\Ahttps://files\.example\.com/download/[A-Za-z0-9_-]{43}\z~', $url);
        $token = basename($url);
        self::assertEquals($file, $links->resolve($token, 100 + 2_592_000 - 1));
        self::assertNull($links->resolve($token, 100 + 2_592_000));
        $rows = $this->db->query('SELECT * FROM attachment_links')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $token), $rows[0]['token_hash']);
        self::assertStringNotContainsString($token, json_encode($rows));
    }

    public function testLaterRecipientDoesNotExtendDeadlineAndExpiredFileCannotBeReissued(): void
    {
        $links = new SqliteAttachmentLinks($this->db,$this->storage,'https://files.example.com/download');
        $file = $this->fixture();
        $first = $links->issue($file,1,100);
        $second = $links->issue($file,2,200);
        self::assertNotSame($first,$second);
        self::assertNull($links->resolve(basename($second),2_592_100));
        try { $links->issue($file,3,2_592_100); self::fail('Expired file reissued.'); }
        catch (ApiFailure $failure) { self::assertFalse($failure->transient); }
    }

    public function testCleanupRevokesLinksButRetainsBlobAndExpiryTombstone(): void
    {
        $links = new SqliteAttachmentLinks($this->db,$this->storage,'https://files.example.com/download');
        $file = $this->fixture();
        $url = $links->issue($file,1,100);
        self::assertSame(0,$links->cleanup(2_592_099));
        self::assertSame(1,$links->cleanup(2_592_100));
        self::assertSame(0,$links->cleanup(2_592_100));
        self::assertFileExists($this->storage->path($file->storageKey));
        self::assertNull($links->resolve(basename($url),101));
        try { $links->issue($file,1,2_592_101); self::fail('Cleanup reset deadline.'); }
        catch (ApiFailure) { self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM attachment_link_files')->fetchColumn()); }
    }

    public function testUnknownMalformedTokensAndMissingBlobFailClosed(): void
    {
        $links = new SqliteAttachmentLinks($this->db,$this->storage,'https://files.example.com/download');
        foreach (['', '../file', str_repeat('a',43), str_repeat('a',10000)] as $token) { self::assertNull($links->resolve($token,1)); }
        $file = $this->fixture(); $url = $links->issue($file,1,100);
        $this->storage->delete($file->storageKey);
        self::assertNull($links->resolve(basename($url),101));
    }

    public function testUnsafeBaseUrlRejectedWithoutCredentialsInError(): void
    {
        foreach (['http://files.example.com/download','https://secret@files.example.com/download','https://files.example.com/download?secret=yes'] as $url) {
            try { new SqliteAttachmentLinks($this->db,$this->storage,$url); self::fail('Unsafe base URL accepted.'); }
            catch (\InvalidArgumentException $failure) { self::assertStringNotContainsString('secret',$failure->getMessage()); }
        }
    }

    public function testReusingStorageKeyCannotChangePublicFilenameOrExpiry(): void
    {
        $links = new SqliteAttachmentLinks($this->db,$this->storage,'https://files.example.com/download');
        $file = $this->fixture();
        $url = $links->issue($file,1,100);
        $replacement = new \Broadcast\Domain\Attachment($file->storageKey,'other.txt',$file->mime,$file->byteSize,$file->sha256);
        try { $links->issue($replacement,2,200); self::fail('Published metadata changed.'); }
        catch (ApiFailure) { self::assertEquals($file,$links->resolve(basename($url),201)); }
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM attachment_links')->fetchColumn());
    }

    public function testCorruptDatabaseFilenameCannotInjectResponseHeaders(): void
    {
        $links = new SqliteAttachmentLinks($this->db,$this->storage,'https://files.example.com/download');
        $url = $links->issue($this->fixture(),1,100);
        $query = $this->db->prepare('UPDATE attachment_link_files SET display_name=?');
        $query->execute(["file.txt\r\nInjected: bad"]);
        self::assertNull($links->resolve(basename($url),101));
    }
}
