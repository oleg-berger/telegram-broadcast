<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Export\XlsxUsers;
use Broadcast\Infrastructure\Runtime\ProcessLock;
use Broadcast\Infrastructure\SqliteStore;
use Broadcast\Maintenance\UserImport;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SQLite3;

require_once dirname(__DIR__, 2) . '/bin/import-users.php';

final class UserImportTest extends TestCase
{
    private string $dir;
    private string $path;
    private string $file;
    private array $users;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/import-test-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->path = $this->dir . '/broadcast.sqlite';
        $store = new SqliteStore($this->path);
        $store->migrate();
        $store->close();
        $this->users = [];
        foreach ([11, 12] as $id) {
            $this->users[] = ['id' => $id, 'name' => 'Test Person', 'country' => 'France', 'company' => 'Company',
                'phone' => '+331234560' . $id, 'email' => "person$id@example.test", 'language' => $id === 11 ? 'RU' : 'EN-GB',
                'status' => 'approved', 'submitted_at' => 1700000000, 'approved_at' => 1700000010];
        }
        $this->file = (new XlsxUsers())->export($this->users);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testRestorePreservesApprovalReservesContactsAndNeverQueuesDelivery(): void
    {
        $import = new UserImport();
        $hash = hash_file('sha256', $this->path);
        $check = $import->run($this->path, $this->file);
        self::assertSame(2, $check['would_insert']);
        self::assertSame($hash, hash_file('sha256', $this->path));
        $result = $import->run($this->path, $this->file, true);
        self::assertSame(2, $result['inserted']);
        self::assertFileExists($result['backup']);
        $store = new SqliteStore($this->path);
        foreach ($this->users as $user) {
            $actual = $store->user($user['id']);
            foreach ($user as $key => $value) {
                self::assertSame($value, $actual[$key]);
            }
            self::assertSame('review', $actual['step']);
            self::assertSame(1, $actual['completed']);
            self::assertSame(1, $actual['subscribed']);
            self::assertSame(0, $actual['choosing_language']);
            self::assertTrue($store->contactTaken('phone', $user['phone'], 999));
            self::assertTrue($store->contactTaken('email', strtoupper($user['email']), 999));
        }
        self::assertNull($store->nextJob(time()));
        $store->close();
        $again = $import->run($this->path, $this->file, true);
        self::assertSame(0, $again['inserted']);
        self::assertSame(2, $again['unchanged']);
        $snapshot = new SQLite3($result['backup'], SQLITE3_OPEN_READONLY);
        self::assertSame(0, $snapshot->querySingle('SELECT COUNT(*) FROM users'));
        $snapshot->close();
    }

    public function testExistingChangedAccountAbortsWholeImportWithoutOverwrite(): void
    {
        $store = new SqliteStore($this->path);
        $store->saveUser($this->users[1] + ['step' => 'review', 'subscribed' => 0, 'completed' => 1, 'choosing_language' => 0]);
        $store->transaction(fn () => $store->queueReply(99, 'Existing job'));
        $store->close();
        try {
            (new UserImport())->run($this->path, $this->file, true);
            self::fail('Conflict should abort.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('example.test', $e->getMessage());
        }
        $store = new SqliteStore($this->path);
        self::assertNull($store->user(11));
        self::assertSame(0, $store->user(12)['subscribed']);
        self::assertSame('message', $store->nextJob(time())['kind']);
        $store->close();
    }

    public function testDuplicateContactsAndUnapprovedRowsRejected(): void
    {
        foreach (['phone', 'email', 'status'] as $field) {
            $users = $this->users;
            $users[1][$field] = $field === 'status' ? 'pending' : $users[0][$field];
            $file = (new XlsxUsers())->export($users);
            try {
                (new UserImport())->run($this->path, $file, true);
                self::fail('Invalid export should abort.');
            } catch (RuntimeException $e) {
                self::assertStringNotContainsString('example.test', $e->getMessage());
                unset($e);
            } finally {
                gc_collect_cycles();
                unlink($file);
            }
        }
        $db = new SQLite3($this->path, SQLITE3_OPEN_READONLY);
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM users'));
        $db->close();
    }

    public function testRunningProcessBlocksImportAndMissingDatabaseIsNeverCreated(): void
    {
        $lock = ProcessLock::acquire($this->dir . '/runtime', 'poll');
        try {
            (new UserImport())->run($this->path, $this->file, true);
            self::fail('Process lock must block import.');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($this->dir . '/backups');
        } finally {
            $lock->release();
        }
        try {
            (new UserImport())->run($this->dir . '/missing.sqlite', $this->file, true);
            self::fail('Missing database must be rejected.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($this->dir . '/missing.sqlite');
        }
    }

    public function testDatabaseFailureRollsBackAllProfilesAndContacts(): void
    {
        $db = new SQLite3($this->path);
        $db->exec("CREATE TRIGGER fail_second BEFORE INSERT ON contacts WHEN NEW.user_id=12 BEGIN SELECT RAISE(ABORT,'test'); END");
        $db->close();
        try {
            (new UserImport())->run($this->path, $this->file, true);
            self::fail('Database failure must abort.');
        } catch (\Exception $e) {
            unset($e);
        }
        $db = new SQLite3($this->path, SQLITE3_OPEN_READONLY);
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM users'));
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM contacts'));
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM jobs'));
        $db->close();
    }
}
