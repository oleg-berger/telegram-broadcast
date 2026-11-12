<?php
declare(strict_types=1);

namespace Broadcast\Tests\Contacts;

use Broadcast\Infrastructure\Contacts\MySqlContactDirectory;
use PDO;
use PHPUnit\Framework\TestCase;

final class MySqlContactDirectoryTest extends TestCase
{
    private PDO $db;
    private MySqlContactDirectory $directory;

    protected function setUp(): void
    {
        $dsn = getenv('CONTACTS_TEST_MYSQL_DSN');
        if (!$dsn) {
            if (getenv('CONTACTS_REQUIRE_MYSQL_TESTS') === '1') { self::fail('Isolated MySQL test DSN is required.'); }
            self::markTestSkipped('Isolated MySQL not configured.');
        }
        self::assertMatchesRegularExpression('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=[A-Za-z0-9_]*test[A-Za-z0-9_]*;charset=utf8mb4\z/', $dsn, 'Tests require an explicitly local isolated test database.');
        $this->db = new PDO($dsn, getenv('CONTACTS_TEST_MYSQL_USER') ?: 'root', getenv('CONTACTS_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['bc_snapshot_recipients', 'bc_snapshots', 'bc_directory_operations', 'bc_contacts'] as $table) { $this->db->exec('DROP TABLE IF EXISTS ' . $table); }
        $this->directory = new MySqlContactDirectory($this->db);
        $this->directory->migrate();
    }

    private function import(string $id = 'import-1'): void
    {
        $this->directory->applyOperation($id, ['type' => 'import', 'rows' => [
            ['phone' => '+33123456789', 'language' => 'FR', 'email' => null],
        ]]);
    }

    public function testImportIsIdempotentAndDoesNotInventTelegramOrEmail(): void
    {
        $this->import(); $this->import();
        $snapshot = $this->directory->snapshot('broadcast-1');
        self::assertCount(1, $snapshot->recipients);
        self::assertSame('whatsapp', $snapshot->recipients[0]['channel']);
        self::assertSame('33123456789', $snapshot->recipients[0]['address']);
        self::assertNull($snapshot->recipients[0]['telegram_user_id']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM bc_contacts')->fetchColumn());
    }

    public function testApprovedTelegramAddsChannelsWithOwnLanguageAndEmailPriority(): void
    {
        $this->import();
        $this->directory->applyOperation('telegram-1', ['type' => 'telegram', 'user_id' => 123, 'revision' => 1,
            'phone' => '+33123456789', 'email' => 'tg@example.test', 'language' => 'RU', 'status' => 'approved', 'subscribed' => true]);
        $this->directory->applyOperation('import-2', ['type' => 'import', 'rows' => [
            ['phone' => '+33123456789', 'language' => 'ES', 'email' => 'manual@example.test'],
        ]]);
        $rows = array_column($this->directory->snapshot('b2')->recipients, null, 'channel');
        self::assertCount(3, $rows);
        self::assertSame('ES', $rows['whatsapp']['language']);
        self::assertSame('RU', $rows['telegram']['language']);
        self::assertSame('tg@example.test', $rows['email']['address']);
        self::assertSame('RU', $rows['email']['language']);
    }

    public function testPendingTelegramDoesNotDisableImportedWhatsApp(): void
    {
        $this->import();
        $this->directory->applyOperation('pending', ['type' => 'telegram', 'user_id' => 123, 'revision' => 1,
            'phone' => '+33123456789', 'email' => 'pending@example.test', 'language' => 'RU', 'status' => 'pending', 'subscribed' => false]);
        $rows = $this->directory->snapshot('b3')->recipients;
        self::assertCount(1, $rows);
        self::assertSame('whatsapp', $rows[0]['channel']);
    }

    public function testUnsubscribeSurvivesImportAndOldEventsButNewApprovalReactivates(): void
    {
        $this->import();
        $op = ['type' => 'telegram', 'user_id' => 123, 'revision' => 4, 'phone' => '+33123456789',
            'email' => 'tg@example.test', 'language' => 'RU', 'status' => 'unsubscribed', 'subscribed' => false];
        $this->directory->applyOperation('stop', $op);
        $this->import('import-again');
        $op['revision'] = 3; $op['status'] = 'approved'; $op['subscribed'] = true;
        $this->directory->applyOperation('stale', $op);
        self::assertSame([], $this->directory->snapshot('stopped')->recipients);
        $op['revision'] = 5;
        $op['reactivate'] = true;
        $this->directory->applyOperation('return', $op);
        self::assertCount(3, $this->directory->snapshot('returned')->recipients);
    }

    public function testSnapshotIsStableAfterManualEditsAndStopStillBlocksDelivery(): void
    {
        $this->import();
        $first = $this->directory->snapshot('stable');
        $this->db->exec("UPDATE bc_contacts SET whatsapp_language='ES', active=0");
        self::assertSame($first->recipients, $this->directory->snapshot('stable')->recipients);
        self::assertFalse($this->directory->deliveryAllowed($first->recipients[0]['contact_id'], 'whatsapp'));
    }

    public function testInvalidManualDataFailsEntireSnapshot(): void
    {
        $this->import();
        $this->db->exec("UPDATE bc_contacts SET whatsapp_language='bad'");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contact directory operation failed.');
        $this->directory->snapshot('invalid');
    }

    public function testImportFailureRollsBackAllRows(): void
    {
        try {
            $this->directory->applyOperation('bad-import', ['type' => 'import', 'rows' => [
                ['phone' => '+33123456789', 'language' => 'FR', 'email' => null],
                ['phone' => 'bad', 'language' => 'FR', 'email' => null],
            ]]);
            self::fail('Expected invalid phone rejection.');
        } catch (\RuntimeException $e) { self::assertSame('Contact directory operation failed.', $e->getMessage()); }
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM bc_contacts')->fetchColumn());
    }

    public function testImportAddsWhatsAppToExistingTelegramButPreservesLaterDisable(): void
    {
        $this->directory->applyOperation('telegram-first', ['type' => 'telegram', 'user_id' => 123, 'revision' => 1,
            'phone' => '+33123456789', 'email' => 'tg@example.test', 'language' => 'RU', 'status' => 'approved', 'subscribed' => true]);
        $this->import();
        self::assertCount(3, $this->directory->snapshot('combined')->recipients);
        $this->db->exec('UPDATE bc_contacts SET whatsapp_enabled=0');
        $this->import('second-import');
        self::assertCount(2, $this->directory->snapshot('wa-disabled')->recipients);
    }

    public function testSubscriptionBeforeFirstImportPersistsTombstone(): void
    {
        $this->directory->applyOperation('early-stop', ['type' => 'subscription', 'phone' => '+33123456789', 'revision' => 4, 'active' => false]);
        $this->import();
        self::assertSame([], $this->directory->snapshot('early-stopped')->recipients);
        $this->directory->applyOperation('late-start', ['type' => 'subscription', 'phone' => '+33123456789', 'revision' => 3, 'active' => true]);
        self::assertSame([], $this->directory->snapshot('still-stopped')->recipients);
        $this->directory->applyOperation('explicit-start', ['type' => 'subscription', 'phone' => '+33123456789', 'revision' => 5, 'active' => true]);
        self::assertCount(1, $this->directory->snapshot('resumed')->recipients);
    }

    public function testOperationIdCannotBeReusedWithDifferentContents(): void
    {
        $this->import();
        $this->expectException(\RuntimeException::class);
        $this->directory->applyOperation('import-1', ['type' => 'import', 'rows' => [
            ['phone' => '+34912345678', 'language' => 'ES', 'email' => null],
        ]]);
    }

    public function testOrdinaryProfileUpdateDoesNotReactivateManualUnsubscribe(): void
    {
        $this->import();
        $op = ['type' => 'telegram', 'user_id' => 123, 'revision' => 1, 'phone' => '+33123456789',
            'email' => 'tg@example.test', 'language' => 'RU', 'status' => 'approved', 'subscribed' => true];
        $this->directory->applyOperation('initial', $op);
        $this->db->exec('UPDATE bc_contacts SET active=0');
        $op['revision'] = 2; $op['subscribed'] = false;
        $this->directory->applyOperation('blocked-bot', $op);
        self::assertSame([], $this->directory->snapshot('manual-stop')->recipients);
    }

    public function testMergedHistoryKeepsFiveTelegramAndEmailMessagesAlongsideMysqlWhatsAppDelivery(): void
    {
        $this->import();
        $store = new \Broadcast\Infrastructure\SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $telegramMessages = [];
        $emailMessages = [];
        $whatsappMessages = [];
        $telegram = $this->createMock(\Broadcast\Application\TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text) use (&$telegramMessages): void {
            if ($id === 12345) { $telegramMessages[] = $text; }
        });
        $mail = $this->createMock(\Broadcast\Application\MailGateway::class);
        $mail->method('send')->willReturnCallback(static function (string $address, string $subject, string $text) use (&$emailMessages): void {
            self::assertSame('new@example.test', $address);
            self::assertSame('ES:Subject', $subject);
            $emailMessages[] = $text;
        });
        $whatsapp = $this->createMock(\Broadcast\Application\WhatsAppGateway::class);
        $whatsapp->method('connected')->willReturn(true);
        $whatsapp->method('sendText')->willReturnCallback(static function (string $address, string $text) use (&$whatsappMessages): string {
            self::assertSame('33123456789', $address);
            $whatsappMessages[] = $text;
            return 'accepted-' . count($whatsappMessages);
        });
        $translator = $this->createMock(\Broadcast\Application\Translator::class);
        $translator->method('translate')->willReturnCallback(static fn (string $text, string $language): string => "$language:$text");
        $worker = new \Broadcast\Application\Worker($store, $telegram, $translator, mail: $mail, whatsapp: $whatsapp, directory: $this->directory);
        $now = time();
        $drain = static function () use ($worker, &$now): void {
            for ($i = 0; $i < 400 && $worker->tick($now += 3); ++$i) {}
            self::assertLessThan(400, $i);
        };
        $approve = static function (int $number) use ($store): void {
            $draft = $store->createDraft(99, "News$number");
            $store->saveDraft($draft['id'], 1, ['subject' => 'Subject', 'status' => 'pending']);
            $store->approveDraft($draft['id'], 2, 100);
        };
        foreach (range(1, 6) as $number) { $approve($number); }
        $drain();
        self::assertSame(['FR:News1', 'FR:News2', 'FR:News3', 'FR:News4', 'FR:News5', 'FR:News6'], $whatsappMessages);

        $identity = $store->ensureIdentity('telegram', '12345');
        $user = $store->user($identity['user_id']);
        $user['phone'] = '+33123456789';
        $user['email'] = 'new@example.test';
        $user['language'] = 'ES';
        $user['status'] = 'pending';
        $user['subscribed'] = 1;
        $store->saveUser($user);
        self::assertTrue($store->decideApplication($user['id'], $user['revision'], 'approved', 100));
        self::assertTrue($store->addCatchup($user['id'], 'ES'));
        $drain();
        self::assertSame(['ES:News2', 'ES:News3', 'ES:News4', 'ES:News5', 'ES:News6'], $telegramMessages);
        self::assertSame($telegramMessages, $emailMessages);
        self::assertCount(6, $whatsappMessages);
        self::assertTrue($store->addCatchup($user['id'], 'ES'));
        $drain();
        self::assertCount(5, $telegramMessages);
        self::assertCount(5, $emailMessages);
        self::assertCount(6, $whatsappMessages);

        $approve(7);
        $drain();
        self::assertCount(6, $telegramMessages);
        self::assertCount(6, $emailMessages);
        self::assertCount(7, $whatsappMessages);
        self::assertSame('ES:News7', $telegramMessages[5]);
        self::assertSame('ES:News7', $emailMessages[5]);
        self::assertSame('FR:News7', $whatsappMessages[6]);
    }
}
