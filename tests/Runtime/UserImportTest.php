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
        $this->file = (new XlsxUsers())->export($this->exportRows($this->users));
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
            self::assertSame($user['id'], $store->identity('telegram', (string) $user['id'])['user_id']);
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
            $file = (new XlsxUsers())->export($this->exportRows($users));
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
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM channel_identities'));
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM contact_reservations'));
        self::assertSame(0, (int) $db->querySingle("SELECT value FROM runtime_state WHERE name='person_sequence'"));
        $db->close();
    }

    public function testNewWorkbookRestoresOnlyExplicitChannelsAndAdvancesPersonSequence(): void
    {
        $rows = [
            $this->users[0] + ['whatsapp_id' => '77010000001', 'whatsapp_verified_phone' => '+77010000001'],
            $this->users[1] + ['telegram_id' => '900012', 'whatsapp_id' => '77010000002', 'whatsapp_verified_phone' => '+77010000002'],
        ];
        $file = (new XlsxUsers())->export($rows);
        try {
            $import = new UserImport();
            self::assertSame(2, $import->run($this->path, $file)['would_insert']);
            self::assertSame(2, $import->run($this->path, $file, true)['inserted']);
            self::assertSame(2, $import->run($this->path, $file, true)['unchanged']);
            $store = new SqliteStore($this->path);
            self::assertNull($store->identity('telegram', '11'));
            self::assertNull($store->identity('telegram', '12'));
            self::assertSame(11, $store->identity('whatsapp', '77010000001')['user_id']);
            self::assertSame('+77010000001', $store->identity('whatsapp', '77010000001')['verified_phone']);
            self::assertSame(12, $store->identity('telegram', '900012')['user_id']);
            self::assertSame(12, $store->identity('whatsapp', '77010000002')['user_id']);
            self::assertCount(1, $store->identities(11));
            self::assertCount(2, $store->identities(12));
            self::assertNull($store->nextJob(time()));
            self::assertSame(13, $store->ensureIdentity('telegram', '900099')['user_id']);
            $store->close();
        } finally {
            unlink($file);
        }
    }

    public function testLegacyWorkbookRestoresTelegramAndPreservesLaterExplicitlyLinkedChannel(): void
    {
        $file = $this->legacyExport($this->users);
        try {
            $import = new UserImport();
            self::assertSame(2, $import->run($this->path, $file, true)['inserted']);
            $db = new SQLite3($this->path);
            $db->exec("INSERT INTO channel_identities(user_id,channel,address,verified_phone) VALUES (11,'whatsapp','77010000001','+77010000001')");
            $db->close();
            self::assertSame(2, $import->run($this->path, $file, true)['unchanged']);
            $store = new SqliteStore($this->path);
            self::assertSame(11, $store->identity('telegram', '11')['user_id']);
            self::assertSame(12, $store->identity('telegram', '12')['user_id']);
            self::assertSame(11, $store->identity('whatsapp', '77010000001')['user_id']);
            self::assertNull($store->nextJob(time()));
            $store->close();
        } finally {
            unlink($file);
        }
    }

    public function testOpaqueLidRemainsUnverifiedAndCannotCreateTelegramIdentity(): void
    {
        $file = (new XlsxUsers())->export([$this->users[0] + ['whatsapp_id' => '123456789012345@lid']]);
        try {
            (new UserImport())->run($this->path, $file, true);
            $store = new SqliteStore($this->path);
            self::assertSame(11, $store->identity('whatsapp', '123456789012345@lid')['user_id']);
            self::assertNull($store->identity('whatsapp', '123456789012345@lid')['verified_phone']);
            self::assertNull($store->identity('telegram', '11'));
            $store->close();
        } finally {
            unlink($file);
        }
    }

    public function testMissingOrContradictoryChannelProofIsRejectedBeforeWriting(): void
    {
        $invalid = [
            [],
            ['whatsapp_id' => '77010000001'],
            ['whatsapp_id' => '77010000001', 'whatsapp_verified_phone' => '+77010000002'],
            ['whatsapp_id' => '123456789012345@lid', 'whatsapp_verified_phone' => '+77010000001'],
            ['telegram_id' => '011'],
            ['telegram_id' => '11', 'whatsapp_verified_phone' => '+77010000001'],
        ];
        foreach ($invalid as $identity) {
            $file = (new XlsxUsers())->export([$this->users[0] + $identity]);
            try {
                (new UserImport())->run($this->path, $file, true);
                self::fail('Unproven identity must not be inferred.');
            } catch (RuntimeException) {
            } finally {
                unlink($file);
            }
        }
        $db = new SQLite3($this->path, SQLITE3_OPEN_READONLY);
        self::assertSame(0, $db->querySingle('SELECT COUNT(*) FROM users'));
        $db->close();
    }

    public function testDuplicateChannelIdentityAcrossProfilesIsRejected(): void
    {
        $file = (new XlsxUsers())->export([
            $this->users[0] + ['telegram_id' => '55'],
            $this->users[1] + ['telegram_id' => '55'],
        ]);
        try {
            $this->expectException(RuntimeException::class);
            (new UserImport())->run($this->path, $file, true);
        } finally {
            unlink($file);
        }
    }

    public function testHistoricalReservationOutsideContactsBlocksDryRunAndImport(): void
    {
        $store = new SqliteStore($this->path);
        $owner = $store->ensureIdentity('telegram', '999');
        $store->close();
        $db = new SQLite3($this->path);
        $statement = $db->prepare("INSERT INTO contact_reservations(field,value,user_id) VALUES ('email',?,?)");
        $statement->bindValue(1, strtoupper($this->users[0]['email']), SQLITE3_TEXT);
        $statement->bindValue(2, $owner['user_id'], SQLITE3_INTEGER);
        $statement->execute();
        $db->close();
        foreach ([false, true] as $confirm) {
            try {
                (new UserImport())->run($this->path, $this->file, $confirm);
                self::fail('Historical reservation must remain occupied.');
            } catch (RuntimeException) {
            }
        }
        $store = new SqliteStore($this->path);
        self::assertNull($store->user(11));
        self::assertNull($store->user(12));
        $store->close();
    }

    public function testForeignCanonicalAndAliasIdentityOwnersBlockDryRunAndImport(): void
    {
        $store = new SqliteStore($this->path);
        $identity = $store->ensureIdentity('whatsapp', '77010000001', '+77010000001');
        $store->close();
        $db = new SQLite3($this->path);
        $statement = $db->prepare("INSERT INTO channel_identity_aliases(channel,address,identity_id) VALUES ('whatsapp','123456789012345@lid',?)");
        $statement->bindValue(1, $identity['id'], SQLITE3_INTEGER);
        $statement->execute();
        $db->close();
        foreach ([
            ['whatsapp_id' => '77010000001', 'whatsapp_verified_phone' => '+77010000001'],
            ['whatsapp_id' => '123456789012345@lid'],
        ] as $fields) {
            $file = (new XlsxUsers())->export([$this->users[0] + $fields]);
            try {
                foreach ([false, true] as $confirm) {
                    try {
                        (new UserImport())->run($this->path, $file, $confirm);
                        self::fail('Foreign address owner must block import.');
                    } catch (RuntimeException) {
                    }
                }
            } finally {
                unlink($file);
            }
        }
        $store = new SqliteStore($this->path);
        self::assertNull($store->user(11));
        self::assertSame($identity['user_id'], $store->identity('whatsapp', '77010000001')['user_id']);
        $store->close();
    }

    public function testWhatsAppPollLockBlocksImportBeforeBackup(): void
    {
        $lock = ProcessLock::acquire($this->dir . '/runtime', 'whatsapp-poll');
        try {
            (new UserImport())->run($this->path, $this->file, true);
            self::fail('WhatsApp polling must be locked during recovery.');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($this->dir . '/backups');
        } finally {
            $lock->release();
        }
    }

    private function exportRows(array $profiles): array
    {
        // These fixtures explicitly represent legacy Telegram users.
        return array_map(static fn (array $profile): array => $profile + ['telegram_id' => (string) $profile['id']], $profiles);
    }

    private function legacyExport(array $users): string
    {
        $file = tempnam(sys_get_temp_dir(), 'legacy-users-');
        $writer = new \OpenSpout\Writer\XLSX\Writer(new \OpenSpout\Writer\XLSX\Options());
        $writer->openToFile($file);
        $headers = ['Telegram ID', 'Имя', 'Страна', 'Организация', 'Телефон', 'Email', 'Язык', 'Статус', 'Дата подачи (UTC)', 'Дата одобрения (UTC)'];
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
        foreach ($users as $user) {
            $values = [$user['id'], $user['name'], $user['country'], $user['company'], $user['phone'], $user['email'],
                $user['language'], $user['status'], gmdate('Y-m-d H:i:s', $user['submitted_at']), gmdate('Y-m-d H:i:s', $user['approved_at'])];
            $writer->addRow(new \OpenSpout\Common\Entity\Row(array_map(static fn ($value) => new \OpenSpout\Common\Entity\Cell\StringCell((string) $value, null), $values)));
        }
        $writer->close();
        return $file;
    }
}
