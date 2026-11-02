<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Integration;

use Broadcast\Application\{MailGateway, TelegramGateway, Translator, WhatsAppGateway, Worker};
use Broadcast\Domain\WhatsAppFailure;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class WorkerWhatsAppTest extends TestCase
{
    private SqliteStore $store;
    private array $sent = [];
    private array $telegram = [];
    private array $mail = [];
    private array $logs = [];
    private bool $connected = true;
    private ?string $failure = null;
    private int $probes = 0;
    private int $attempts = 0;
    private int $now = 2000000000;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
    }

    private function worker(): Worker
    {
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(function ($id, $text): void { $this->telegram[] = [$id, $text]; });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(static fn ($text, $language): string => $language . ':' . $text);
        $mail = $this->createMock(MailGateway::class);
        $mail->method('send')->willReturnCallback(function (...$args): void { $this->mail[] = $args; });
        $whatsapp = $this->createMock(WhatsAppGateway::class);
        $whatsapp->method('connected')->willReturnCallback(function (): bool { ++$this->probes; return $this->connected; });
        $whatsapp->method('sendText')->willReturnCallback(function (string $address, string $text): string {
            ++$this->attempts;
            if ($this->failure !== null) { throw new WhatsAppFailure($this->failure); }
            $this->sent[] = [$address, $text];
            return 'wa-message-' . count($this->sent);
        });
        return new Worker($this->store, $telegram, $translator,
            function (array $event): void { $this->logs[] = $event; }, $mail,
            whatsapp: $whatsapp, whatsappStaffIds: [99]);
    }

    private function person(string $channel = 'whatsapp', string $address = '447700900123'): array
    {
        $identity = $this->store->ensureIdentity($channel, $address, $channel === 'whatsapp' ? '+' . $address : null);
        $user = $this->store->user($identity['user_id']);
        $user = array_replace($user, ['language' => 'FR', 'status' => 'approved', 'subscribed' => 1,
            'step' => 'review', 'name' => 'Private name', 'company' => 'Company', 'country' => 'Country',
            'phone' => '+447700900123', 'email' => 'private@example.test', 'completed' => 1, 'choosing_language' => 0]);
        $this->store->saveUser($user);
        return $identity;
    }

    private function broadcast(string $text = 'Announcement'): int
    {
        return $this->store->transaction(function () use ($text): int {
            $draft = $this->store->createDraft(98, $text);
            $this->store->saveDraft($draft['id'], $draft['version'], ['subject' => 'Subject', 'status' => 'pending']);
            return $this->store->approveDraft($draft['id'], $draft['version'] + 1, 99)['id'];
        });
    }

    private function ticks(Worker $worker, int $count = 30): void
    {
        for ($i = 0; $i < $count; ++$i) { $worker->tick($this->now++); }
    }

    public function testHealthProbeAndSendAreSeparateTicksAndAcceptedIsNotDelivered(): void
    {
        $identity = $this->person();
        $this->store->queueUserReply($identity['user_id'], 'Private text', identityId: $identity['id']);
        $worker = $this->worker();
        self::assertTrue($worker->tick($this->now++));
        self::assertSame(1, $this->probes);
        self::assertSame(0, $this->attempts);
        $worker->tick($this->now++);
        self::assertCount(1, $this->sent);
        self::assertNull($this->store->nextJob($this->now));
        self::assertTrue($this->store->recordWhatsAppReceipt('wa-message-1', 'delivered', '447700900123'));
        $this->ticks($worker);
        self::assertCount(1, $this->sent);
    }

    public function testDaysOfGlobalOutageDoNotSpendRecipientsAndOtherChannelsContinue(): void
    {
        $this->person();
        $this->person('telegram', '12345');
        $this->connected = false;
        $first = $this->broadcast('First');
        $second = $this->broadcast('Second');
        $worker = $this->worker();
        $this->ticks($worker, 70);
        self::assertCount(0, $this->sent);
        self::assertCount(4, $this->mail);
        self::assertContains([12345, 'FR:First'], $this->telegram);
        self::assertContains([12345, 'FR:Second'], $this->telegram);
        for ($day = 0; $day < 6; ++$day) { $this->now += 86400; $this->ticks($worker, 5); }
        self::assertSame(0, $this->attempts);
        $this->connected = true;
        $this->now += 120;
        $this->ticks($worker, 40);
        self::assertSame([['447700900123', 'FR:First'], ['447700900123', 'FR:Second']], $this->sent);
        self::assertFalse($this->store->retryBroadcast($first));
        self::assertFalse($this->store->retryBroadcast($second));
        $alerts = array_filter($this->telegram, static fn ($item) => str_contains($item[1], 'WhatsApp') && !str_contains($item[1], 'Рассылка'));
        self::assertCount(2, $alerts, 'Only one outage and one recovery alert.');
    }

    public function testLostResponseIsUnknownAndNormalRetryNeverResendsIt(): void
    {
        $this->person();
        $broadcast = $this->broadcast();
        $this->failure = 'uncertain';
        $worker = $this->worker();
        $this->ticks($worker);
        self::assertSame(1, $this->attempts);
        self::assertFalse($this->store->retryBroadcast($broadcast));
        $this->failure = null;
        $this->ticks($this->worker());
        self::assertSame(1, $this->attempts);
        self::assertCount(1, $this->mail);
        $output = json_encode($this->logs, JSON_THROW_ON_ERROR);
        foreach (['Private', '447700900123', 'private@example.test', 'Announcement', 'wa-message'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    public function testCrashAfterDurableIntentDoesNotResendAfterWorkerRestart(): void
    {
        $identity = $this->person();
        $this->store->queueUserReply($identity['user_id'], 'Private text', identityId: $identity['id']);
        $this->store->setChannelState('whatsapp', 'connected', $this->now + 60);
        $job = $this->store->nextJob($this->now);
        self::assertNotNull($job);
        self::assertTrue($this->store->beginWhatsAppPart($job['id'], 0, $this->now));
        $this->ticks($this->worker());
        self::assertSame(0, $this->attempts);
        self::assertNull($this->store->nextJob($this->now));
    }

    public function testKnownTransientFailureRetriesOnlyTheUnsentPart(): void
    {
        $identity = $this->person();
        $text = str_repeat('é👋' . "\n", 16000);
        $this->store->queueUserReply($identity['user_id'], $text, identityId: $identity['id']);
        $worker = $this->worker();
        $worker->tick($this->now++);
        $worker->tick($this->now++);
        self::assertCount(1, $this->sent);
        $this->failure = 'transient';
        $worker->tick($this->now++);
        $this->failure = null;
        $this->now += 10;
        $this->ticks($worker);
        self::assertSame($text, implode('', array_column($this->sent, 1)));
        self::assertSame(count($this->sent) + 1, $this->attempts);
    }

    public function testUnsubscribePreventsPendingWhatsAppAndEmail(): void
    {
        $identity = $this->person();
        $this->broadcast();
        self::assertTrue($this->store->unsubscribeUser($identity['user_id']));
        $this->ticks($this->worker());
        self::assertCount(0, $this->sent);
        self::assertCount(0, $this->mail);
    }

    public function testTranslatedCommentGoesToWhatsAppWithoutTelegramUserIdLeak(): void
    {
        $identity = $this->person();
        $this->store->queueComment($identity['user_id'], 'FR', 'Correct name', 'Correction', 99);
        $this->ticks($this->worker());
        self::assertSame([['447700900123', "Correction\n\nFR:Correct name"]], $this->sent);
        self::assertCount(0, $this->telegram);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainPaths')]
    public function testUnknownTransitionAndStaffNoticeCommitAtomically(bool $recover): void
    {
        $identity = $this->person();
        $this->store->queueUserReply($identity['user_id'], 'Private text', identityId: $identity['id']);
        $this->store->setChannelState('whatsapp', 'connected', $this->now + 60);
        $job = $this->store->nextJob($this->now);
        if ($recover) {
            $this->store->beginWhatsAppPart($job['id'], 0, $this->now);
        } else {
            $this->failure = 'uncertain';
        }
        $db = (new \ReflectionProperty($this->store, 'db'))->getValue($this->store);
        $db->exec("CREATE TRIGGER reject_notice BEFORE INSERT ON jobs WHEN NEW.kind='message' AND NEW.channel='telegram' BEGIN SELECT RAISE(ABORT,'fixture'); END");
        try {
            $this->worker()->tick($this->now++);
            self::fail('Notification failure must roll back the unknown transition.');
        } catch (\PDOException) {
            self::assertSame('sending', $db->query('SELECT status FROM jobs WHERE id=' . $job['id'])->fetchColumn());
        }
        $db->exec('DROP TRIGGER reject_notice');
        $before = $this->attempts;
        $this->failure = null;
        $this->ticks($this->worker());
        self::assertSame($before, $this->attempts);
        self::assertSame('unknown', $db->query('SELECT status FROM jobs WHERE id=' . $job['id'])->fetchColumn());
        self::assertCount(1, $this->telegram);
    }

    public static function uncertainPaths(): array { return [[false], [true]]; }
}
