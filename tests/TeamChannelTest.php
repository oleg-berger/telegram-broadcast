<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
use Broadcast\Application\MailGateway;
use Broadcast\Application\Messages;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\TextParts;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TeamChannelTest extends TestCase
{
    private const int CHANNEL = -1004293904431;
    private SqliteStore $store;
    private Kernel $bot;
    private Worker $worker;
    private array $sent = [];
    private array $mail = [];
    private array $translations = [];
    private array $events = [];
    private ?ApiFailure $channelFailure = null;
    private ?string $failRussianField = null;
    private ?string $russianReplacement = null;
    private ?string $failLanguage = null;
    private int $now;
    private int $update = 0;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:', self::CHANNEL);
        $this->store->migrate();
        $this->bot = new Kernel($this->store, [99], [100]);
        $this->now = time();
        $this->worker = $this->worker();
    }

    private function worker(): Worker
    {
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(function (int $id, string $text, array $options): void {
            if ($id === self::CHANNEL && $this->channelFailure !== null) {
                throw $this->channelFailure;
            }
            $this->sent[] = [$id, $text, $options];
        });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(function (string $text, string $language): string {
            $this->translations[] = [$language, $text];
            if ($language === 'RU' && $text === $this->failRussianField) {
                if ($this->russianReplacement !== null) { return $this->russianReplacement; }
                throw new ApiFailure('Private upstream text and token', true);
            }
            if ($language === $this->failLanguage) { throw new ApiFailure('Private language failure', true); }
            return $language . ':' . $text;
        });
        $mail = $this->createMock(MailGateway::class);
        $mail->method('send')->willReturnCallback(function (string $to, string $subject, string $text): void {
            $this->mail[] = [$to, $subject, $text];
        });
        return new Worker($this->store, $telegram, $translator,
            function (array $event): void { $this->events[] = $event; }, $mail);
    }

    private function say(int $actor, string $text): array
    {
        $update = ['update_id' => ++$this->update, 'message' => [
            'from' => ['id' => $actor], 'chat' => ['id' => $actor, 'type' => 'private'], 'text' => $text,
        ]];
        $this->bot->handle($update);
        return $update;
    }

    private function click(int $actor, string $data): array
    {
        $update = ['update_id' => ++$this->update, 'callback_query' => [
            'id' => 'team-' . $this->update, 'from' => ['id' => $actor], 'data' => $data,
            'message' => ['chat' => ['id' => $actor, 'type' => 'private']],
        ]];
        $this->bot->handle($update);
        return $update;
    }

    private function user(int $id, string $language): void
    {
        $this->store->saveUser(['id' => $id, 'language' => $language, 'step' => 'review',
            'name' => 'Private name', 'company' => 'Company', 'country' => 'France',
            'phone' => '+44770090000' . $id, 'email' => "private$id@example.com", 'status' => 'approved',
            'subscribed' => 1, 'completed' => 1, 'choosing_language' => 0]);
    }

    private function approve(string $text = 'Foreign announcement', string $subject = 'Foreign subject', int $author = 99): void
    {
        $this->say($author, $text);
        $this->say($author, $subject);
        if ($author === 100) {
            $this->click(100, 'draft:send:1:2');
        } else {
            $this->click(99, 'draft:submit:1:2');
            $this->click(100, 'draft:approve:1:3');
        }
    }

    private function drain(): void
    {
        for ($i = 0; $i < 200 && $this->worker->tick($this->now += 60); $i++) {}
        self::assertLessThan(200, $i);
    }

    private function channelMessages(): array
    {
        return array_values(array_filter($this->sent, static fn (array $message): bool => $message[0] === self::CHANNEL));
    }

    private function reports(): array
    {
        return array_values(array_filter($this->sent, static fn (array $message): bool => str_contains($message[1], 'Telegram:')));
    }

    public function testOnlyApprovedAnnouncementIsPublishedWithJustRussianSubjectAndBody(): void
    {
        $this->user(1, 'FR');
        $this->say(2, '/start');
        $this->say(100, '/requests');
        $this->say(99, 'Foreign {id} announcement');
        $this->say(99, 'Foreign {subject} subject');
        $this->click(99, 'draft:submit:1:2');
        $this->drain();
        self::assertSame([], $this->channelMessages());
        self::assertNull($this->store->broadcast(1));
        $approved = $this->click(100, 'draft:approve:1:3');
        $this->bot->handle($approved);
        $this->click(100, 'draft:approve:1:3');
        $this->drain();
        self::assertSame([[self::CHANNEL, "RU:Foreign {subject} subject\n\nRU:Foreign {id} announcement", []]], $this->channelMessages());
        self::assertSame(self::CHANNEL, $this->store->broadcast(1)['team_channel_id']);
        self::assertSame([["private1@example.com", 'FR:Foreign {subject} subject', 'FR:Foreign {id} announcement']], $this->mail);
        self::assertCount(1, $this->reports());
        self::assertSame(100, $this->reports()[0][0]);
        self::assertStringContainsString('Канал команды (RU): опубликовано 1, пропущено 0, ошибки 0.', $this->reports()[0][1]);
        self::assertStringNotContainsString('Private name', $this->channelMessages()[0][1]);
        $logged = json_encode($this->events);
        foreach (['Foreign', 'private1@', 'Private name', 'upstream', 'token'] as $private) {
            self::assertStringNotContainsString($private, $logged);
        }
    }

    public function testSuperadminOwnBroadcastPublishesEvenWithEmptyUserAudience(): void
    {
        $this->approve(author: 100);
        $this->drain();
        self::assertCount(1, $this->channelMessages());
        self::assertSame([], $this->mail);
        self::assertSame('ready', $this->store->broadcast(1)['status']);
        self::assertStringContainsString('Telegram: отправлено 0', $this->reports()[0][1]);
        self::assertStringContainsString('Канал команды (RU): опубликовано 1', $this->reports()[0][1]);
    }

    public function testRussianUserAndChannelReuseOneTranslationOfEachField(): void
    {
        $this->user(1, 'RU');
        $this->approve();
        $this->drain();
        self::assertSame([['RU', 'Foreign announcement'], ['RU', 'Foreign subject']], $this->translations);
        self::assertCount(1, $this->channelMessages());
        self::assertSame('RU:Foreign announcement', $this->mail[0][2]);
    }

    public function testPermissionFailureDoesNotCancelUserDeliveriesOrSubscriptions(): void
    {
        $this->user(1, 'FR');
        $this->channelFailure = new ApiFailure('Private token and announcement', blocked: true);
        $this->approve();
        $this->drain();
        self::assertSame([], $this->channelMessages());
        self::assertCount(1, $this->mail);
        self::assertCount(1, array_filter($this->sent, static fn (array $message): bool => $message[0] === 1));
        self::assertSame(1, $this->store->user(1)['subscribed']);
        self::assertSame('approved', $this->store->user(1)['status']);
        self::assertSame('ready', $this->store->broadcast(1)['status']);
        self::assertStringContainsString('Telegram: отправлено 1', $this->reports()[0][1]);
        self::assertStringContainsString('Email (принято почтовым сервисом): отправлено 1', $this->reports()[0][1]);
        self::assertStringContainsString('Канал команды (RU): опубликовано 0, пропущено 0, ошибки 1.', $this->reports()[0][1]);
        $this->channelFailure = null;
        $this->say(100, '/retry 1');
        $this->drain();
        self::assertCount(1, $this->channelMessages());
        self::assertCount(1, $this->mail);
        self::assertCount(1, array_filter($this->sent, static fn (array $message): bool => $message[0] === 1));
        self::assertCount(2, $this->reports());
        self::assertStringContainsString('Канал команды (RU): опубликовано 1', $this->reports()[1][1]);
        self::assertStringNotContainsString('Private token', json_encode($this->events));
    }

    #[DataProvider('russianFields')]
    public function testMissingRussianTranslationNeverFallsBackAndDoesNotFailFrenchDelivery(string $field): void
    {
        $this->user(1, 'FR');
        $this->failRussianField = $field;
        $this->approve();
        $this->drain();
        self::assertSame([], $this->channelMessages());
        self::assertCount(1, $this->mail);
        self::assertSame('ready', $this->store->broadcast(1)['status']);
        self::assertCount(5, array_filter($this->translations, static fn (array $call): bool => $call === ['RU', $field]));
        self::assertStringContainsString('Канал команды (RU): опубликовано 0, пропущено 0, ошибки 1.', $this->reports()[0][1]);
        self::assertStringNotContainsString('Private upstream', json_encode($this->events));
        $this->failRussianField = null;
        $this->say(100, '/retry 1');
        $this->drain();
        self::assertCount(1, $this->channelMessages());
        self::assertSame("RU:Foreign subject\n\nRU:Foreign announcement", $this->channelMessages()[0][1]);
        self::assertCount(1, $this->mail);
        self::assertStringContainsString('Канал команды (RU): опубликовано 1', $this->reports()[1][1]);
    }

    public static function russianFields(): array
    {
        return [['Foreign announcement'], ['Foreign subject']];
    }

    #[DataProvider('invalidRussianOutput')]
    public function testInvalidRussianOutputDoesNotPublishOriginalOrCancelFrenchDelivery(string $field, string $output): void
    {
        $this->user(1, 'FR');
        $this->failRussianField = $field;
        $this->russianReplacement = $output;
        $this->approve();
        $this->drain();
        self::assertSame([], $this->channelMessages());
        self::assertCount(1, $this->mail);
        self::assertSame('ready', $this->store->broadcast(1)['status']);
        self::assertStringContainsString('Канал команды (RU): опубликовано 0, пропущено 0, ошибки 1.', $this->reports()[0][1]);
    }

    public static function invalidRussianOutput(): array
    {
        return [['Foreign announcement', ''], ['Foreign subject', ''], ['Foreign subject', "Invalid\nsubject"]];
    }

    public function testUserTranslationFailureDoesNotCancelPreparedRussianChannelPublication(): void
    {
        $this->user(1, 'FR');
        $this->failLanguage = 'FR';
        $this->approve();
        $this->drain();
        self::assertSame('failed', $this->store->broadcast(1)['status']);
        self::assertCount(1, $this->channelMessages());
        self::assertSame([], $this->mail);
        self::assertStringContainsString('Telegram: отправлено 0, пропущено 0, ошибки 1.', $this->reports()[0][1]);
        self::assertStringContainsString('Канал команды (RU): опубликовано 1, пропущено 0, ошибки 0.', $this->reports()[0][1]);
    }

    public function testMultipartRetryAfterAndWorkerRestartResumeFirstUnsentPart(): void
    {
        $text = str_repeat('Ж😀', 2300);
        $this->approve($text, 'Subject');
        while (count($this->channelMessages()) === 0) {
            self::assertTrue($this->worker->tick(++$this->now));
        }
        $this->channelFailure = new ApiFailure('Temporary upstream text', true, 120);
        self::assertTrue($this->worker->tick(++$this->now));
        self::assertNull($this->store->nextJob($this->now + 119));
        self::assertSame(1, $this->store->nextJob($this->now + 120)['part']);
        self::assertSame(1, $this->store->nextJob($this->now + 120)['attempts']);
        self::assertSame([], $this->reports());
        $this->channelFailure = null;
        $this->worker = $this->worker();
        $this->now += 120;
        $this->drain();
        self::assertSame(TextParts::split("RU:Subject\n\nRU:" . $text), array_column($this->channelMessages(), 1));
        self::assertCount(2, $this->translations);
        self::assertCount(1, $this->reports());
        self::assertStringContainsString('Канал команды (RU): опубликовано 1', $this->reports()[0][1]);
    }

    public function testTransientChannelFailureStopsAfterFiveAttemptsAndCanBeRetried(): void
    {
        $this->channelFailure = new ApiFailure('Temporary failure', true);
        $this->approve();
        $this->drain();
        $failures = array_values(array_filter($this->events, static fn (array $event): bool => $event['kind'] === 'channel_post'));
        self::assertSame([1, 2, 3, 4, 5], array_column($failures, 'attempt'));
        self::assertSame('job_failed', $failures[4]['event']);
        self::assertStringContainsString('ошибки 1.', $this->reports()[0][1]);
        $this->channelFailure = null;
        $this->say(100, '/retry 1');
        $this->drain();
        self::assertCount(1, $this->channelMessages());
    }

    public function testCatchupAndRetryOtherFailuresNeverRepublishSuccessfulChannelHistory(): void
    {
        $this->user(1, 'FR');
        $this->approve();
        $this->drain();
        $firstPost = $this->channelMessages();
        $this->user(2, 'ES');
        self::assertTrue($this->store->transaction(fn () => $this->store->addCatchup(2, 'ES')));
        $this->drain();
        $this->say(100, '/retry 1');
        $this->drain();
        self::assertSame($firstPost, $this->channelMessages());
        self::assertCount(2, $this->mail);
        self::assertCount(2, $this->reports());
        $this->approveSecond();
        $this->drain();
        self::assertCount(2, $this->channelMessages());
        self::assertSame($firstPost[0], $this->channelMessages()[0]);
        self::assertSame("RU:Second subject\n\nRU:Second announcement", $this->channelMessages()[1][1]);
    }

    private function approveSecond(): void
    {
        $this->say(99, 'Second announcement');
        $this->say(99, 'Second subject');
        $this->click(99, 'draft:submit:2:2');
        $this->click(100, 'draft:approve:2:3');
    }

    public function testApprovalRollbackIncludesChannelJobAndUpdateReceipt(): void
    {
        $this->say(99, 'Foreign announcement');
        $this->say(99, 'Foreign subject');
        $this->click(99, 'draft:submit:1:2');
        $db = (new \ReflectionProperty(SqliteStore::class, 'db'))->getValue($this->store);
        $db->exec("CREATE TRIGGER fail_channel BEFORE INSERT ON jobs WHEN NEW.kind='channel_post' BEGIN SELECT RAISE(ABORT,'Failure'); END");
        $update = ['update_id' => ++$this->update, 'callback_query' => [
            'id' => 'rollback', 'from' => ['id' => 100], 'data' => 'draft:approve:1:3',
            'message' => ['chat' => ['id' => 100, 'type' => 'private']],
        ]];
        try {
            $this->bot->handle($update);
            self::fail('Channel enqueue failure must roll back approval.');
        } catch (\PDOException) {
            self::assertSame('pending', $this->store->draft(1)['status']);
            self::assertNull($this->store->broadcast(1));
            self::assertSame($this->update, $this->store->offset());
        }
        $db->exec('DROP TRIGGER fail_channel');
        $this->bot->handle($update);
        $this->drain();
        self::assertCount(1, $this->channelMessages());
    }

    public function testChannelTargetSnapshotSurvivesConfigurationChangesAndOldBroadcastsAreNotBackfilled(): void
    {
        $path = sys_get_temp_dir() . '/team-target-' . bin2hex(random_bytes(8)) . '.sqlite';
        $store = new SqliteStore($path, self::CHANNEL);
        $store->migrate();
        $store->transaction(function () use ($store): void {
            $draft = $store->createDraft(99, 'Snapshot body');
            $store->saveDraft($draft['id'], 1, ['subject' => 'Snapshot subject', 'status' => 'pending']);
            $store->approveDraft($draft['id'], 2, 100);
        });
        $store->close();
        try {
            $this->store = new SqliteStore($path);
            $this->store->migrate();
            $this->worker = $this->worker();
            $this->drain();
            self::assertSame([[self::CHANNEL, "RU:Snapshot subject\n\nRU:Snapshot body", []]], $this->channelMessages());
            $this->store->transaction(function (): void {
                $draft = $this->store->createDraft(99, 'Disabled body');
                $this->store->saveDraft($draft['id'], 1, ['subject' => 'Disabled subject', 'status' => 'pending']);
                $this->store->approveDraft($draft['id'], 2, 100);
            });
            self::assertNull($this->store->broadcast(2)['team_channel_id']);
            $this->store->close();
            $this->store = new SqliteStore($path, self::CHANNEL);
            $this->store->migrate();
            $this->worker = $this->worker();
            $this->drain();
            self::assertCount(1, $this->channelMessages());
            self::assertStringNotContainsString('Канал команды', $this->reports()[1][1]);
        } finally {
            $this->store->close();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testMigrationPreservesOldBroadcastHistoryWithoutCreatingChannelJobs(): void
    {
        $path = sys_get_temp_dir() . '/team-upgrade-' . bin2hex(random_bytes(8)) . '.sqlite';
        $db = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE migrations (version TEXT PRIMARY KEY)');
        foreach (['001_initial.sql', '002_approvals_and_email.sql'] as $version) {
            $db->exec(file_get_contents(dirname(__DIR__) . '/migrations/' . $version));
            $db->prepare('INSERT INTO migrations(version) VALUES (?)')->execute([$version]);
        }
        $db->exec("INSERT INTO broadcasts(admin_id,text,created_at,subject,approved_by,status,reported) VALUES (99,'Historical body',1,'Historical subject',100,'ready',1)");
        unset($db);
        $store = new SqliteStore($path, self::CHANNEL);
        try {
            $store->migrate();
            $store->migrate();
            self::assertSame('Historical body', $store->broadcast(1)['text']);
            self::assertSame('Historical subject', $store->broadcast(1)['subject']);
            self::assertNull($store->broadcast(1)['team_channel_id']);
            $store->refreshBroadcasts();
            self::assertNull($store->nextJob(time()));
        } finally {
            $store->close();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }
}
