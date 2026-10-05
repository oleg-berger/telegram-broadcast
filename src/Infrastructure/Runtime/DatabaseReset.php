<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use Broadcast\Infrastructure\SqliteStore;
use InvalidArgumentException;
use RuntimeException;

/** Offline maintenance with a retained backup and rehearsal before changing live data. */
final class DatabaseReset
{
    public function run(string $path, bool $confirm = false): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Reset requires an existing database.');
        }
        $runtime = dirname($path) . '/runtime';
        $locks = [ProcessLock::acquire($runtime, 'poll'), ProcessLock::acquire($runtime, 'worker'), ProcessLock::acquire($runtime, 'backup')];
        $store = new SqliteStore($path);
        $before = $store->inspectBusinessData();
        if (!$confirm) {
            return ['confirmed' => false, 'before' => $before, 'next_update' => $store->offset()];
        }

        // A separate directory for each reset keeps rollback copies out of routine retention.
        $directory = dirname($path) . '/backups/before-reset/reset-' . bin2hex(random_bytes(8));
        $backup = new SqliteBackup();
        $saved = $backup->create($path, $directory);
        $copyPath = $directory . '/rehearsal.sqlite';
        try {
            $backup->restore($saved, $copyPath);
            $copy = new SqliteStore($copyPath);
            if ($copy->inspectBusinessData() !== $before) {
                throw new RuntimeException('Reset rehearsal snapshot does not match.');
            }
            $cutoff = time();
            $rehearsal = $copy->resetBusinessData($cutoff);
            if (array_sum($copy->inspectBusinessData()) !== 0) {
                throw new RuntimeException('Reset rehearsal failed.');
            }
            $copy->close();
            unset($copy);
            $result = $store->resetBusinessData($cutoff);
            if ($result !== $rehearsal) {
                throw new RuntimeException('Reset differs from rehearsal.');
            }
            return ['confirmed' => true, 'before' => $before, 'after' => $store->inspectBusinessData(),
                'backup' => basename($directory) . '/' . basename($saved), 'rehearsal_verified' => true, ...$result];
        } finally {
            if (isset($copy)) {
                $copy->close();
            }
            unset($copy);
            foreach ([$copyPath, $copyPath . '-wal', $copyPath . '-shm'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }
}
