<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Application\Kernel;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Infrastructure\Runtime\DatabaseReset;
use Broadcast\Infrastructure\Runtime\ProcessLock;
use Broadcast\Infrastructure\Runtime\SqliteBackup;
use Broadcast\Infrastructure\SqliteStore;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseResetTest extends TestCase
{
    private string $directory;
    private string $path;
    private SqliteStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/broadcast-reset-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->path = $this->directory . '/live.sqlite';
        $this->store = new SqliteStore($this->path);
        $this->store->migrate();
        $this->store->transaction(function (): void {
            $this->store->acceptUpdate(40);
            $this->store->saveUser(['id' => 7, 'language' => 'RU', 'step' => 'review', 'name' => 'Private name',
                'company' => 'Private company', 'country' => 'France', 'phone' => '+447700900007',
                'email' => 'private@example.com', 'subscribed' => 0, 'completed' => 0, 'choosing_language' => 0]);
            $this->store->submitApplication(7);
            $this->store->decideApplication(7, 1, 'approved', 100);
            $draft = $this->store->createDraft(99, 'Private announcement');
            $this->store->saveDraft($draft['id'], $draft['version'], ['subject' => 'Private subject', 'status' => 'pending']);
            $this->store->approveDraft($draft['id'], 2, 100);
            $this->store->saveTranslation(1, 'RU', 'Private translation');
            $this->store->saveTranslationSubject(1, 'RU', 'Private translated subject');
            $this->store->saveSession(99, ['kind' => 'draft:return', 'id' => 1, 'version' => 2]);
            $this->store->queueReply(7, 'Private notification');
            $this->store->queueComment(7, 'RU', 'Private comment', 'Prefix', 100);
            $this->store->queueExport(100);
            $this->store->setMailNextAt(9999999999);
        });
    }

    protected function tearDown(): void
    {
        unset($this->store);
        $this->removeDirectory($this->directory);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    private function database(): PDO
    {
        return new PDO('sqlite:' . $this->path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testInspectionDoesNotChangeDataOrCreateBackup(): void
    {
        $before = $this->store->inspectBusinessData();
        $result = (new DatabaseReset())->run($this->path);
        self::assertFalse($result['confirmed']);
        self::assertSame($before, $result['before']);
        self::assertSame($before, $this->store->inspectBusinessData());
        self::assertSame(41, $result['next_update']);
        self::assertDirectoryDoesNotExist($this->directory . '/backups');
    }

    public function testResetRehearsesAndRetainsRestorableBackupAndSequences(): void
    {
        $before = $this->store->inspectBusinessData();
        $db = $this->database();
        $sequences = $db->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
        $migrations = $db->query('SELECT version FROM migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        unset($db);
        $result = (new DatabaseReset())->run($this->path, true);
        self::assertTrue($result['confirmed']);
        self::assertTrue($result['rehearsal_verified']);
        self::assertSame($before, $result['before']);
        self::assertSame(0, array_sum($result['after']));
        self::assertSame(41, $this->store->offset());
        self::assertSame(3, $result['revision_floor']);
        self::assertSame(0, $this->store->mailNextAt());
        $db = $this->database();
        self::assertSame($sequences, $db->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR));
        self::assertSame($migrations, $db->query('SELECT version FROM migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        unset($db);
        $saved = $this->directory . '/backups/before-reset/' . $result['backup'];
        self::assertFileExists($saved);
        self::assertFileDoesNotExist(dirname($saved) . '/rehearsal.sqlite');
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($saved) & 0777);
            self::assertSame(0700, fileperms(dirname($saved)) & 0777);
        }
        $restoredPath = $this->directory . '/restored.sqlite';
        (new SqliteBackup())->restore($saved, $restoredPath);
        $restored = new SqliteStore($restoredPath);
        self::assertSame($before, $restored->inspectBusinessData());
        self::assertSame('private@example.com', $restored->user(7)['email']);
        self::assertSame('Private announcement', $restored->broadcast(1)['text']);
        self::assertSame('Private translation', $restored->translation(1, 'RU'));
        self::assertNotNull($restored->session(99));
        self::assertNotNull($restored->nextJob(time()));
        self::assertTrue($restored->contactTaken('email', 'private@example.com', 8));
        self::assertSame(41, $restored->offset());
        self::assertStringNotContainsString('private@example.com', json_encode($result));
        self::assertStringNotContainsString('Private', json_encode($result));
    }

    #[DataProvider('processRoles')]
    public function testResetRefusesWhileAProcessHoldsItsLock(string $role): void
    {
        $lock = ProcessLock::acquire($this->directory . '/runtime', $role);
        $before = $this->store->inspectBusinessData();
        try {
            (new DatabaseReset())->run($this->path, true);
            self::fail('Active process must prevent reset.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->store->inspectBusinessData());
            self::assertDirectoryDoesNotExist($this->directory . '/backups');
        } finally {
            $lock->release();
        }
    }

    public static function processRoles(): array
    {
        return [['poll'], ['worker'], ['backup']];
    }

    public function testFailedBackupLeavesBusinessDataIntact(): void
    {
        file_put_contents($this->directory . '/backups', 'not a directory');
        $before = $this->store->inspectBusinessData();
        // A filesystem warning is a safe exception under the console's strict handler.
        set_error_handler(static function (): never { throw new RuntimeException('Filesystem operation failed.'); });
        try {
            (new DatabaseReset())->run($this->path, true);
            self::fail('Unavailable backup destination must prevent reset.');
        } catch (RuntimeException) {
            self::assertSame($before, $this->store->inspectBusinessData());
        } finally {
            restore_error_handler();
        }
    }

    public function testUnknownSchemaIsRejectedBeforeBackupOrDeletion(): void
    {
        $db = $this->database();
        $db->exec('CREATE TABLE future_business_data (id INTEGER PRIMARY KEY)');
        unset($db);
        try {
            (new DatabaseReset())->run($this->path, true);
            self::fail('Unknown schema must require review.');
        } catch (RuntimeException) {
            self::assertNotNull($this->store->user(7));
            self::assertNotNull($this->store->broadcast(1));
            self::assertDirectoryDoesNotExist($this->directory . '/backups');
        }
    }

    public function testFailedRehearsalNeverTouchesLiveBusinessData(): void
    {
        $db = $this->database();
        $db->exec("CREATE TRIGGER stop_reset BEFORE DELETE ON users BEGIN SELECT RAISE(ABORT,'Private upstream failure'); END");
        unset($db);
        $before = $this->store->inspectBusinessData();
        try {
            (new DatabaseReset())->run($this->path, true);
            self::fail('Failed rehearsal must prevent live reset.');
        } catch (\PDOException) {
            self::assertSame($before, $this->store->inspectBusinessData());
            self::assertCount(1, glob($this->directory . '/backups/before-reset/reset-*/*.sqlite'));
        }
    }

    public function testLiveTransactionRollsBackOnFailure(): void
    {
        $db = $this->database();
        $db->exec("CREATE TRIGGER stop_reset BEFORE DELETE ON users BEGIN SELECT RAISE(ABORT,'Failure'); END");
        unset($db);
        $before = $this->store->inspectBusinessData();
        try {
            $this->store->resetBusinessData(time());
            self::fail('Failed deletion must roll back all earlier deletions.');
        } catch (\PDOException) {
            self::assertSame($before, $this->store->inspectBusinessData());
            self::assertSame(9999999999, $this->store->mailNextAt());
            self::assertTrue($this->store->transaction(fn () => $this->store->acceptUpdate(41)));
        }
    }

    public function testNewRegistrationRequiresFreshButtonsAndApprovalAfterReset(): void
    {
        $reset = (new DatabaseReset())->run($this->path, true);
        $cutoff = $reset['cutoff'];
        $bot = new Kernel($this->store, [99], [100]);
        $update = 40;
        $say = function (int $id, string $text, ?int $date) use ($bot, &$update): void {
            $bot->handle(['update_id' => ++$update, 'message' => [
                'date' => $date, 'from' => ['id' => $id], 'chat' => ['id' => $id, 'type' => 'private'], 'text' => $text,
            ]]);
        };
        $click = function (int $id, string $data, int $date) use ($bot, &$update): void {
            $bot->handle(['update_id' => ++$update, 'callback_query' => [
                'id' => 'reset-' . $update, 'from' => ['id' => $id], 'data' => $data,
                'message' => ['date' => $date, 'chat' => ['id' => $id, 'type' => 'private']],
            ]]);
        };
        $say(7, '/start', $cutoff - 1);
        $say(7, '/start', $cutoff);
        $say(7, '/start', null);
        $say(99, 'Accumulated test announcement', $cutoff);
        self::assertNull($this->store->user(7));
        self::assertNull($this->store->draft(2));
        self::assertSame(0, array_sum($this->store->inspectBusinessData()));
        self::assertSame($update + 1, $this->store->offset());
        $say(7, '/start', $cutoff + 1);
        self::assertSame('language', $this->store->user(7)['step']);
        self::assertSame(3, $this->store->user(7)['revision']);
        $click(7, 'language:FR', $cutoff);
        self::assertSame('RU', $this->store->user(7)['language']);
        $click(7, 'language:RU', $cutoff + 1);
        $click(7, 'reg:begin', $cutoff);
        self::assertSame('begin', $this->store->user(7)['step']);
        $click(7, 'reg:begin', $cutoff + 1);
        $say(7, 'New name', $cutoff + 1);
        $say(7, 'New company', $cutoff + 1);
        $say(7, '+447700900007', $cutoff + 1);
        $click(7, 'reg:country:other', $cutoff + 1);
        $say(7, 'France', $cutoff + 1);
        $say(7, 'private@example.com', $cutoff + 1);
        $click(7, 'reg:submit', $cutoff);
        self::assertSame('draft', $this->store->user(7)['status']);
        $click(7, 'reg:submit', $cutoff + 1);
        self::assertSame('pending', $this->store->user(7)['status']);
        self::assertSame(4, $this->store->user(7)['revision']);
        $click(100, 'app:approve:7:1', $cutoff + 1);
        self::assertSame('pending', $this->store->user(7)['status']);
        $click(100, 'app:approve:7:4', $cutoff);
        self::assertSame('pending', $this->store->user(7)['status']);
        $click(100, 'app:approve:7:4', $cutoff + 1);
        self::assertSame('approved', $this->store->user(7)['status']);
        self::assertSame(1, $this->store->user(7)['subscribed']);
        self::assertFalse($this->store->addCatchup(7, 'RU'));
        $say(99, 'New announcement', $cutoff + 1);
        $say(99, 'New subject', $cutoff + 1);
        $click(100, 'draft:approve:1:2', $cutoff + 1);
        self::assertNull($this->store->broadcast(2));
        self::assertSame('draft', $this->store->draft(2)['status']);
        $telegram = $this->createMock(TelegramGateway::class);
        $sent = [];
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text) use (&$sent): void {
            $sent[] = $text;
        });
        $worker = new Worker($this->store, $telegram, $this->createMock(Translator::class));
        for ($tick = 0; $tick < 100 && $worker->tick($cutoff + 10); $tick++) {}
        self::assertLessThan(100, $tick);
        self::assertNotEmpty($sent);
        self::assertStringNotContainsString('Private announcement', implode('\n', $sent));
        self::assertStringNotContainsString('Private notification', implode('\n', $sent));
        self::assertNull($this->store->nextJob($cutoff + 10));
    }

    public function testRepeatedResetKeepsEarlierBackupAndNeverRewindsGuards(): void
    {
        $first = (new DatabaseReset())->run($this->path, true);
        $firstBackup = $this->directory . '/backups/before-reset/' . $first['backup'];
        touch($firstBackup, 1);
        $second = (new DatabaseReset())->run($this->path, true);
        self::assertFileExists($firstBackup);
        self::assertGreaterThan($first['revision_floor'], $second['revision_floor']);
        self::assertGreaterThanOrEqual($first['cutoff'], $second['cutoff']);
        self::assertSame($first['next_update'], $second['next_update']);
        self::assertNotSame($first['backup'], $second['backup']);
    }
}
