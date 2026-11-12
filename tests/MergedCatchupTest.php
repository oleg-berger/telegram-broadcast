<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\ContactDirectory;
use Broadcast\Application\MailGateway;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\RecipientSnapshot;
use Broadcast\Infrastructure\SqliteStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class MergedCatchupTest extends TestCase
{
    private function approve(SqliteStore $store, string $text, int $author = 99): int
    {
        $draft = $store->createDraft($author, $text);
        $store->saveDraft($draft['id'], 1, ['subject' => 'Subject', 'status' => 'pending']);
        return $store->approveDraft($draft['id'], 2, 100)['id'];
    }

    private function user(SqliteStore $store, int $address): array
    {
        $identity = $store->ensureIdentity('telegram', (string) $address);
        $user = $store->user($identity['user_id']);
        $user['status'] = 'approved';
        $user['language'] = 'ES';
        $user['subscribed'] = 1;
        $user['phone'] = '+4477009000' . $address;
        $user['email'] = "user$address@example.test";
        $store->saveUser($user);
        return $user;
    }

    private function snapshot(array $user, int $address): RecipientSnapshot
    {
        $common = ['contact_id' => $address, 'telegram_user_id' => $address, 'phone' => $user['phone'], 'language' => 'ES'];
        return new RecipientSnapshot('history-' . $address, 1, [
            $common + ['channel' => 'telegram', 'address' => (string) $address],
            $common + ['channel' => 'email', 'address' => $user['email']],
            $common + ['channel' => 'whatsapp', 'address' => substr($user['phone'], 1)],
        ]);
    }

    public function testDirectoryHistoryKeepsFiveApprovedIdsAndSurvivesOtherLanguagesFailures(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        foreach (range(1, 6) as $number) {
            $id = $this->approve($store, "News$number");
            $store->publishAudience($id, new RecipientSnapshot("original-$id", 1, []));
        }
        $this->approve($store, 'Own announcement', 55);
        $store->createBroadcast(99, 'Unapproved legacy row');
        $user = $this->user($store, 55);
        self::assertNotSame(55, $user['id']);
        $db->exec("UPDATE jobs SET status='done'; UPDATE broadcasts SET status='ready';
            UPDATE broadcasts SET status='failed' WHERE id=3;
            UPDATE broadcasts SET status='preparing' WHERE id=4;
            INSERT INTO jobs(kind,broadcast_id,language,status,initial) VALUES ('translate',4,'FR','pending',1)");
        self::assertTrue($store->addCatchup($user['id'], 'ES'));
        self::assertTrue($store->addCatchup($user['id'], 'ES'));
        self::assertSame([2, 3, 4, 5, 6], $db->query("SELECT broadcast_id FROM jobs WHERE kind='directory_catchup' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $lateId = $this->approve($store, 'Approved after history selection');
        $store->publishAudience($lateId, new RecipientSnapshot('late', 1, []));
        $db->exec("UPDATE jobs SET status='done' WHERE kind='audience_snapshot'");

        $directory = $this->createMock(ContactDirectory::class);
        $directory->expects(self::exactly(5))->method('snapshot')->willReturn($this->snapshot($user, 55));
        $directory->method('deliveryAllowed')->willReturn(true);
        $telegramMessages = [];
        $emailMessages = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $address, string $text) use (&$telegramMessages): void {
            if ($address === 55) { $telegramMessages[] = $text; }
        });
        $mail = $this->createMock(MailGateway::class);
        $mail->method('send')->willReturnCallback(static function (string $address, string $subject, string $text) use (&$emailMessages): void {
            self::assertSame('user55@example.test', $address);
            self::assertSame('ES:Subject', $subject);
            $emailMessages[] = $text;
        });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(static function (string $text, string $language): string {
            if ($language === 'FR') { throw new \Broadcast\Domain\ApiFailure('Synthetic failure'); }
            return "$language:$text";
        });
        $worker = new Worker($store, $telegram, $translator, mail: $mail, directory: $directory);
        for ($i = 0; $i < 200 && $worker->tick(1000 + $i * 3); ++$i) {}
        self::assertLessThan(200, $i);
        self::assertSame(['ES:News2', 'ES:News3', 'ES:News4', 'ES:News5', 'ES:News6'], $telegramMessages);
        self::assertSame($telegramMessages, $emailMessages);
        self::assertSame(10, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery' AND catchup=1 AND status='done'")->fetchColumn());
        self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery' AND channel='whatsapp'")->fetchColumn());
        self::assertSame('failed', $store->broadcast(4)['status']);
    }

    public function testDirectoryRecoveryHasOneBudgetAndDoesNotReopenOldDeliveries(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $id = $this->approve($store, 'News');
        $store->publishAudience($id, new RecipientSnapshot('original', 1, []));
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        $db->exec("INSERT INTO jobs(kind,broadcast_id,language,status,attempts) VALUES
            ('translate',1,'ES','failed',5),('translate_subject',1,'ES','failed',5)");
        $first = $this->user($store, 55);
        self::assertTrue($store->publishDirectoryCatchup(1, $first['id'], $this->snapshot($first, 55)));
        self::assertSame([['status' => 'pending', 'attempts' => 0, 'catchup_recovered' => 1], ['status' => 'pending', 'attempts' => 0, 'catchup_recovered' => 1]],
            $db->query("SELECT status,attempts,catchup_recovered FROM jobs WHERE kind IN ('translate','translate_subject') ORDER BY id")->fetchAll());
        $db->exec("UPDATE jobs SET status='failed',attempts=5 WHERE kind IN ('translate','translate_subject');
            UPDATE jobs SET status='done',part=2 WHERE kind='delivery' AND channel='telegram';
            UPDATE jobs SET status='failed',attempts=5 WHERE kind='delivery' AND channel='email'");
        self::assertFalse($store->publishDirectoryCatchup(1, $first['id'], $this->snapshot($first, 55)));
        $second = $this->user($store, 66);
        self::assertTrue($store->publishDirectoryCatchup(1, $second['id'], $this->snapshot($second, 66)));
        self::assertSame(2, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind IN ('translate','translate_subject') AND status='failed' AND attempts=5 AND catchup_recovered=1")->fetchColumn());
        self::assertSame([['status' => 'done', 'part' => 2], ['status' => 'failed', 'part' => 0]],
            $db->query("SELECT status,part FROM jobs WHERE kind='delivery' AND user_id=" . $first['id'] . ' ORDER BY id')->fetchAll());
        $store->refreshBroadcasts();
        self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery' AND status='pending'")->fetchColumn());
    }

    public function testDelayedAudienceSnapshotKeepsInitialTranslationBarrierAfterCatchup(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $this->approve($store, 'News');
        $user = $this->user($store, 55);
        self::assertTrue($store->publishDirectoryCatchup(1, $user['id'], $this->snapshot($user, 55)));
        self::assertTrue($store->publishAudience(1, new RecipientSnapshot('delayed', 1, [[
            'contact_id' => 99, 'telegram_user_id' => null, 'phone' => '+447700900099',
            'channel' => 'whatsapp', 'address' => '447700900099', 'language' => 'ES',
        ]])));
        $store->refreshBroadcasts();
        self::assertSame('preparing', $store->broadcast(1)['status']);
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        self::assertSame(2, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind IN ('translate','translate_subject') AND initial=1")->fetchColumn());
        $db->exec("UPDATE jobs SET status='failed',attempts=5 WHERE kind='translate'");
        $store->refreshBroadcasts();
        self::assertSame('failed', $store->broadcast(1)['status']);
        self::assertSame('failed', $db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='whatsapp'")->fetchColumn());
    }

    public function testChannelCatchupUsesExternalAuthorIdAndQueuesFiveOnlyOnSelectedChannel(): void
    {
        $store = new SqliteStore(':memory:');
        $store->migrate();
        foreach (range(1, 6) as $number) { $this->approve($store, "News$number"); }
        $this->approve($store, 'Own announcement', 55);
        $user = $this->user($store, 55);
        self::assertTrue($store->addChannelCatchup($user['id'], 'ES', 'telegram'));
        self::assertFalse($store->addChannelCatchup($user['id'], 'ES', 'telegram'));
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        self::assertSame([2, 3, 4, 5, 6], $db->query("SELECT broadcast_id FROM jobs WHERE kind='delivery' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(5, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery' AND catchup=1 AND channel='telegram' AND json_extract(payload,'$.address')='55'")->fetchColumn());
    }

    public function testRestartLogsUncertainDeliveryWithoutRequiringFullJobPayload(): void
    {
        $store = new SqliteStore(':memory:');
        $store->migrate();
        $user = $this->user($store, 55);
        $this->approve($store, 'News');
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        $db->exec("UPDATE jobs SET status='done'; UPDATE jobs SET status='sending' WHERE kind='delivery' AND channel='telegram'");
        $logs = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $address): void { self::assertNotSame(55, $address); });
        $worker = new Worker($store, $telegram, $this->createMock(Translator::class), static function (array $event) use (&$logs): void { $logs[] = $event; });
        $worker->tick(1000);
        $uncertain = array_values(array_filter($logs, static fn (array $event): bool => $event['event'] === 'job_uncertain'));
        self::assertCount(1, $uncertain);
        self::assertSame(['event', 'job_id', 'kind'], array_keys($uncertain[0]));
        self::assertSame('unknown', $db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='telegram' AND user_id=" . $user['id'])->fetchColumn());
    }
}
