<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    public function testHelpDoesNotRequireSecrets(): void
    {
        [$exit, $output] = $this->console('help');
        self::assertSame(0, $exit);
        self::assertStringContainsString('migrate', $output);
        self::assertStringContainsString('worker', $output);
    }

    public function testUnknownCommandFailsWithoutDumpingEnvironment(): void
    {
        [$exit, $output] = $this->console('unknown-command');
        self::assertSame(1, $exit);
        self::assertStringNotContainsString('Stack trace', $output);
        self::assertStringContainsString('command_failed', $output);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restoreSources')]
    public function testRestorePreservesAnOldSelectedBackup(string $subdirectory): void
    {
        $dir = sys_get_temp_dir() . '/console-restore-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $path = $dir . '/live.sqlite';
        (new \Broadcast\Infrastructure\SqliteStore($path))->migrate();
        $backup = (new \Broadcast\Infrastructure\Runtime\SqliteBackup())->create($path, $dir . '/' . $subdirectory);
        touch($backup, time() - 30 * 86400);
        try {
            [$exit, $output] = $this->console('restore', [$backup, '--confirm'], ['DATABASE_PATH' => $path]);
            self::assertSame(0, $exit, $output);
            self::assertFileExists($backup);
        } finally {
            foreach (['backups/before-restore', 'backups', 'runtime', ''] as $folder) {
                $directory = $dir . ($folder === '' ? '' : '/' . $folder);
                foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
                if (is_dir($directory)) { rmdir($directory); }
            }
        }
    }

    public static function restoreSources(): array
    {
        return [['backups'], ['backups/before-restore']];
    }

    public function testResetDefaultsToInspectionAndLogsOnlyCounts(): void
    {
        $dir = sys_get_temp_dir() . '/console-reset-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $path = $dir . '/live.sqlite';
        $store = new \Broadcast\Infrastructure\SqliteStore($path);
        $store->migrate();
        $store->createDraft(99, 'Confidential announcement');
        try {
            [$exit, $output] = $this->console('reset-data', [], ['DATABASE_PATH' => $path]);
            self::assertSame(0, $exit, $output);
            self::assertStringContainsString('reset_inspected', $output);
            self::assertStringNotContainsString('Confidential', $output);
            self::assertSame('Confidential announcement', $store->draft(1)['text']);
            self::assertDirectoryDoesNotExist($dir . '/backups');
            [$exit, $output] = $this->console('reset-data', ['--typo'], ['DATABASE_PATH' => $path]);
            self::assertSame(1, $exit);
            self::assertStringContainsString('command_failed', $output);
            self::assertNotNull($store->draft(1));
        } finally {
            unset($store);
            foreach (glob($dir . '/runtime/*') ?: [] as $file) { unlink($file); }
            if (is_dir($dir . '/runtime')) { rmdir($dir . '/runtime'); }
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            rmdir($dir);
        }
    }

    private function console(string $command, array $arguments = [], array $environment = []): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', $command, ...$arguments], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, array_merge(getenv(), $environment));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
