<?php
declare(strict_types=1);

namespace Broadcast\Tests\Files;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Files\LocalAttachmentStorage;
use PHPUnit\Framework\TestCase;

final class AttachmentStorageTest extends TestCase
{
    private string $directory;
    private string $source;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/broadcast-files-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->source = $this->directory . '/source';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory . '/blobs')) {
            foreach (new \DirectoryIterator($this->directory . '/blobs') as $file) {
                if (!$file->isDot()) { unlink($file->getPathname()); }
            }
        }
        if (is_dir($this->directory . '/blobs')) { rmdir($this->directory . '/blobs'); }
        if (is_file($this->source)) { unlink($this->source); }
        rmdir($this->directory);
    }

    public function testPrivateDownloadPathIsExclusiveAndCleanupOnlyRemovesOldExactTemporaryNames(): void
    {
        $storage=new LocalAttachmentStorage($this->directory.'/blobs');$now=time();
        $download=$storage->downloadPath();
        self::assertSame(realpath($this->directory.'/blobs'),dirname($download));
        self::assertMatchesRegularExpression('/^\.[a-f0-9]{64}\.download$/D',basename($download));
        self::assertFileDoesNotExist($download);$handle=fopen($download,'xb');self::assertIsResource($handle);fclose($handle);touch($download,$now-86401);
        $old=$this->directory.'/blobs/.'.str_repeat('a',64).'.tmp';file_put_contents($old,'old');touch($old,$now-86401);
        $fresh=$this->directory.'/blobs/.'.str_repeat('b',64).'.tmp';file_put_contents($fresh,'fresh');touch($fresh,$now-86400);
        $unrelated=$this->directory.'/blobs/.keep.tmp';file_put_contents($unrelated,'keep');touch($unrelated,$now-86401);
        $blob=$this->directory.'/blobs/'.str_repeat('c',64);file_put_contents($blob,'blob');touch($blob,$now-86401);
        self::assertSame(2,$storage->cleanupTemporary($now));
        self::assertFileDoesNotExist($old);self::assertFileDoesNotExist($download);self::assertFileExists($fresh);self::assertFileExists($unrelated);self::assertFileExists($blob);
    }

    public function testStoresExactBytesAndMetadataUnderUnpredictableKey(): void
    {
        file_put_contents($this->source, "original document\n");
        $storage = new LocalAttachmentStorage($this->directory . '/blobs');
        $attachment = $storage->put($this->source, 'Отчёт.md');
        self::assertSame('Отчёт.md', $attachment->displayName);
        self::assertSame(18, $attachment->byteSize);
        self::assertSame(hash_file('sha256', $this->source), $attachment->sha256);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $attachment->storageKey);
        self::assertSame(file_get_contents($this->source), file_get_contents($storage->path($attachment->storageKey)));
        self::assertStringNotContainsString($attachment->displayName, $storage->path($attachment->storageKey));
        self::assertFileExists($this->source);
        $storage->delete($attachment->storageKey);
        $storage->delete($attachment->storageKey);
        self::assertFileDoesNotExist($this->directory . '/blobs/' . $attachment->storageKey);
    }

    public function testExactLimitIsAcceptedAndLargerFileLeavesNoPartialBlob(): void
    {
        $handle = fopen($this->source, 'wb');
        ftruncate($handle, 20_000_000);
        fclose($handle);
        $storage = new LocalAttachmentStorage($this->directory . '/blobs');
        $attachment = $storage->put($this->source, 'limit.bin');
        self::assertSame(20_000_000, $attachment->byteSize);
        file_put_contents($this->source, 'x', FILE_APPEND);
        try {
            $storage->put($this->source, 'too-large.bin');
            self::fail('Oversized attachment accepted.');
        } catch (ApiFailure $failure) {
            self::assertFalse($failure->transient);
        }
        self::assertCount(1, glob($this->directory . '/blobs/*'));
    }

    public function testRejectsUnsafeNamesAndKeysWithoutLeakingInput(): void
    {
        file_put_contents($this->source, 'data');
        $storage = new LocalAttachmentStorage($this->directory . '/blobs');
        foreach (["secret\r\nBcc: hidden@example.com", '../private.txt', 'a\\b.txt', "bad\0name"] as $name) {
            try {
                $storage->put($this->source, $name);
                self::fail('Unsafe name accepted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringNotContainsString($name, $failure->getMessage());
            }
        }
        foreach (['../source', 'a/b', 'C:\\private', str_repeat('a', 63)] as $key) {
            try {
                $storage->path($key);
                self::fail('Unsafe key accepted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Invalid attachment key.', $failure->getMessage());
            }
        }
        self::assertSame('data', file_get_contents($this->source));
        self::assertSame([], glob($this->directory . '/blobs/*'));
    }

    public function testRejectsMissingSourceWithoutLeakingPath(): void
    {
        $storage = new LocalAttachmentStorage($this->directory . '/blobs');
        try {
            $storage->put($this->source, 'private.txt');
            self::fail('Missing source accepted.');
        } catch (ApiFailure $failure) {
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString($this->source, $failure->getMessage());
        }
    }
}
