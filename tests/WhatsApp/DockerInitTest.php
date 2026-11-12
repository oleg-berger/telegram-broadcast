<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp;

use Broadcast\Infrastructure\SqliteStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class DockerInitTest extends TestCase
{
    public function testReadinessRequiresInitializationAndAllMigrationsWithoutTakingWorkerLock(): void
    {
        $directory = sys_get_temp_dir() . '/broadcast-init-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/application.sqlite';
        mkdir($directory . '/runtime', 0700);
        $lockPath = $directory . '/runtime/worker.lock';
        $lock = fopen($lockPath, 'c+');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            self::assertSame(2, $this->runInit($path, '--check'));
            $store = new SqliteStore($path);
            $store->migrate();
            $store->resetBusinessData(12345);
            $store->close();
            self::assertSame(2, $this->runInit($path, '--check'));

            flock($lock, LOCK_UN);
            self::assertSame(0, $this->runInit($path));
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            self::assertSame(0, $this->runInit($path, '--check'));

            $db = new PDO('sqlite:' . $path);
            self::assertSame(12345, (int) $db->query("SELECT value FROM runtime_state WHERE name='reset_cutoff'")->fetchColumn());
            $db->exec("DELETE FROM migrations WHERE version='008_attachment_links.sql'");
            self::assertSame(2, $this->runInit($path, '--check'));
        } finally {
            unset($db, $store);
            fclose($lock);
            foreach (glob($directory . '/runtime/*') as $file) {
                unlink($file);
            }
            rmdir($directory . '/runtime');
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function runInit(string $path, string ...$args): int
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/tools/whatsapp/docker-init.php', ...$args];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            env_vars: ['DATABASE_PATH' => $path] + getenv());
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }
        return proc_close($process);
    }
}
