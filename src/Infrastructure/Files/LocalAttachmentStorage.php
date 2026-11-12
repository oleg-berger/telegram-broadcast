<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Files;

use Broadcast\Application\AttachmentStagingStorage;
use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;

final class LocalAttachmentStorage implements AttachmentStagingStorage
{
    private string $root;

    public function __construct(string $directory)
    {
        try {
            $this->assertNoLinks($directory);
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                throw new \RuntimeException();
            }
            $root = realpath($directory);
            if ($root === false || !is_dir($root)) {
                throw new \RuntimeException();
            }
            $this->root = $root;
            $this->assertNoLinks($this->root);
        } catch (\Throwable) {
            throw new ApiFailure('Attachment storage unavailable.', true);
        }
    }

    public function put(string $sourcePath, string $displayName): Attachment
    {
        Attachment::validateName($displayName);
        $input = $output = null;
        $temporary = null;
        try {
            $this->assertNoLinks($this->root);
            $this->assertNoLinks($sourcePath);
            clearstatcache(true, $sourcePath);
            if (!is_file($sourcePath)) {
                throw new ApiFailure('Attachment source unavailable.');
            }
            $size = filesize($sourcePath);
            if ($size === false || $size > Attachment::MAX_BYTES) {
                throw new ApiFailure('Attachment exceeds size limit.');
            }
            $key = bin2hex(random_bytes(32));
            $temporary = $this->root . DIRECTORY_SEPARATOR . '.' . $key . '.tmp';
            $input = fopen($sourcePath, 'rb');
            $output = fopen($temporary, 'xb');
            if ($input === false || $output === false) {
                throw new \RuntimeException();
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new \RuntimeException();
                }
                $bytes += strlen($chunk);
                if ($bytes > Attachment::MAX_BYTES) {
                    throw new ApiFailure('Attachment exceeds size limit.');
                }
                hash_update($hash, $chunk);
                if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException();
                }
            }
            if (!fflush($output)) {
                throw new \RuntimeException();
            }
            fclose($input);
            $input = null;
            fclose($output);
            $output = null;
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
            $attachment = new Attachment($key, $displayName, $mime ?: 'application/octet-stream', $bytes, hash_final($hash));
            $this->assertNoLinks($this->root);
            if (!rename($temporary, $this->root . DIRECTORY_SEPARATOR . $key)) {
                throw new \RuntimeException();
            }
            $temporary = null;
            return $attachment;
        } catch (ApiFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new ApiFailure('Attachment storage operation failed.', true);
        } finally {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
            if ($temporary !== null && is_file($temporary)) {
                try { unlink($temporary); } catch (\Throwable) { /* A later cleanup can remove an incomplete temporary file. */ }
            }
        }
    }

    public function downloadPath(): string
    {
        try {
            $this->assertNoLinks($this->root);
            return $this->root . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(32)) . '.download';
        } catch (\Throwable) { throw new ApiFailure('Attachment staging unavailable.', true); }
    }

    public function cleanupTemporary(int $now): int
    {
        try {
            $this->assertNoLinks($this->root);
            $removed = 0;
            foreach (new \DirectoryIterator($this->root) as $file) {
                if (!preg_match('/^\.[a-f0-9]{64}\.(tmp|download)$/D', $file->getFilename()) || $file->isLink() || !$file->isFile()
                    || $file->getMTime() >= $now - 86400) { continue; }
                $path = $file->getPathname();
                $this->assertNoLinks($path);
                if (realpath(dirname($path)) !== $this->root || !unlink($path)) { throw new \RuntimeException(); }
                ++$removed;
            }
            return $removed;
        } catch (\Throwable) { throw new ApiFailure('Attachment staging cleanup failed.', true); }
    }

    public function path(string $storageKey): string
    {
        Attachment::validateKey($storageKey);
        try {
            $path = $this->root . DIRECTORY_SEPARATOR . $storageKey;
            $this->assertNoLinks($path);
            clearstatcache(true, $path);
            if (!is_file($path) || realpath(dirname($path)) !== $this->root) {
                throw new \RuntimeException();
            }
            return $path;
        } catch (\Throwable) {
            throw new ApiFailure('Attachment unavailable.');
        }
    }

    public function delete(string $storageKey): void
    {
        Attachment::validateKey($storageKey);
        try {
            $path = $this->root . DIRECTORY_SEPARATOR . $storageKey;
            $this->assertNoLinks($path);
            if (file_exists($path) && !unlink($this->path($storageKey))) {
                throw new \RuntimeException();
            }
        } catch (\Throwable) {
            throw new ApiFailure('Attachment removal failed.', true);
        }
    }

    private function assertNoLinks(string $path): void
    {
        do {
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new \RuntimeException();
            }
            $parent = dirname($path);
            if ($parent === $path) { break; }
            $path = $parent;
        } while ($path !== '.');
    }
}
