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
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class SuperadminBroadcastTest extends TestCase
{
    private SqliteStore $store;
    private Kernel $bot;
    private int $update = 0;
    private array $logs = [];

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->bot = new Kernel($this->store, [99], [100, 101]);
    }

    private function say(int $id, string $text): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'message' => ['from' => ['id' => $id], 'chat' => ['id' => $id, 'type' => 'private'], 'text' => $text]]);
    }

    private function click(int $id, string $data): array
    {
        $update = ['update_id' => ++$this->update, 'callback_query' => ['id' => (string) $this->update, 'from' => ['id' => $id], 'message' => ['chat' => ['id' => $id, 'type' => 'private']], 'data' => $data]];
        $this->bot->handle($update);
        return $update;
    }

    private function draft(int $author = 100): array
    {
        $this->say($author, 'Private original <tag> 😀');
        $this->say($author, 'Private subject');
        return $this->store->draft(1);
    }

    private function user(int $id, string $language, string $status = 'approved'): void
    {
        $this->store->saveUser([
            'id' => $id, 'language' => $language, 'step' => 'done', 'name' => 'Private name', 'company' => 'Private company',
            'country' => 'France', 'phone' => '', 'email' => "private$id@example.com", 'status' => $status,
            'subscribed' => 1, 'completed' => 1, 'choosing_language' => 0,
        ]);
    }

    private function preview(array $draft): string
    {
        return Messages::text('RU', 'draft_subject_label') . ' ' . $draft['subject'] . "\n\n" . $draft['text'] . "\n\n" . Messages::text('RU', 'draft_send_prompt');
    }

    private function drain(?string $failedText = null): array
    {
        $messages = $mails = $translations = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text, array $options) use (&$messages): void {
            $messages[] = ['id' => $id, 'text' => $text, 'options' => $options];
        });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(static function (string $text, string $language) use (&$translations, $failedText): string {
            $translations[] = [$text, $language];
            if ($text === $failedText) {
                throw new ApiFailure('Private upstream response', transient: false);
            }
            return $language . ':' . $text;
        });
        $mailer = $this->createMock(MailGateway::class);
        $mailer->method('send')->willReturnCallback(static function (string $to, string $subject, string $text) use (&$mails): void {
            $mails[] = [$to, $subject, $text];
        });
        $worker = new Worker($this->store, $telegram, $translator, function (array $record): void { $this->logs[] = $record; }, $mailer);
        $now = time();
        for ($i = 0; $i < 300 && $worker->tick($now + $i * 10); ++$i) {}
        self::assertLessThan(300, $i);
        return [$messages, $translations, $mails];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broadcastAuthors')]
    public function testRegisteredAuthorReceivesNotificationsButNotTheirOwnDelivery(int $author): void
    {
        $this->user($author, 'ES');
        $this->user(1, 'FR');
        $draft = $this->draft($author);
        $this->drain();
        if ($author === 99) {
            $this->click($author, "draft:submit:{$draft['id']}:{$draft['version']}");
            $this->click(100, "draft:approve:{$draft['id']}:" . ($draft['version'] + 1));
        } else {
            $this->click($author, "draft:send:{$draft['id']}:{$draft['version']}");
        }
        [$messages, $translations, $mails] = $this->drain();
        self::assertSame([['private1@example.com', 'FR:Private subject', 'FR:Private original <tag> 😀']], $mails);
        self::assertSame(['FR', 'FR'], array_column($translations, 1));
        self::assertCount(1, array_filter($messages, static fn ($message) => $message['id'] === 1 && $message['text'] === 'FR:Private original <tag> 😀'));
        $authorMessages = array_values(array_filter($messages, static fn ($message) => $message['id'] === $author));
        $notification = Messages::text('RU', $author === 99 ? 'broadcast_approved_author' : 'broadcast_started', ['id' => 1, 'recipients' => 1]);
        self::assertContains($notification, array_column($authorMessages, 'text'));
        foreach ($authorMessages as $message) {
            self::assertStringNotContainsString('Private original', $message['text']);
        }
        self::assertSame(1, $this->store->broadcast(1)['reported']);
        $reports = array_values(array_filter($messages, static fn ($message) => str_contains($message['text'], 'Telegram:')));
        self::assertCount(1, $reports);
        self::assertSame(100, $reports[0]['id']);
        self::assertSame(Messages::text('RU', 'broadcast_report', ['id' => 1]) . "\n" . Messages::text('RU', 'broadcast_report_telegram', ['done' => 1, 'skipped' => 0, 'failed' => 0]) . "\n" . Messages::text('RU', 'broadcast_report_email', ['done' => 1, 'skipped' => 0, 'failed' => 0]), $reports[0]['text']);
        self::assertFalse($this->store->transaction(fn () => $this->store->addCatchup($author, 'ES')));
        self::assertSame(1, $this->store->broadcast(1)['reported']);
        self::assertSame([[], [], []], $this->drain());
        self::assertStringNotContainsString('Private', json_encode($this->logs, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private', json_encode($this->logs, JSON_THROW_ON_ERROR));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broadcastAuthors')]
    public function testOnlyRegisteredAuthorDoesNotCreateDeliveryOrTranslationJobs(int $author): void
    {
        $this->user($author, 'ES');
        $draft = $this->draft($author);
        $this->drain();
        if ($author === 99) {
            $this->click($author, "draft:submit:{$draft['id']}:{$draft['version']}");
            $this->click(100, "draft:approve:{$draft['id']}:" . ($draft['version'] + 1));
        } else {
            $this->click($author, "draft:send:{$draft['id']}:{$draft['version']}");
        }
        [$messages, $translations, $mails] = $this->drain();
        self::assertSame([], $translations);
        self::assertSame([], $mails);
        self::assertSame('ready', $this->store->broadcast(1)['status']);
        self::assertSame(1, $this->store->broadcast(1)['reported']);
        $reports = array_values(array_filter($messages, static fn ($message) => str_contains($message['text'], 'Telegram:')));
        self::assertCount(1, $reports);
        self::assertStringContainsString(Messages::text('RU', 'broadcast_report_telegram', ['done' => 0, 'skipped' => 0, 'failed' => 0]), $reports[0]['text']);
        self::assertStringContainsString(Messages::text('RU', 'broadcast_report_email', ['done' => 0, 'skipped' => 0, 'failed' => 0]), $reports[0]['text']);
    }

    public static function broadcastAuthors(): array
    {
        return ['admin' => [99], 'superadmin' => [100]];
    }

    public function testOwnPreviewAndEditingContainOnlyTheSelectedField(): void
    {
        $draft = $this->draft();
        [$messages, $translations] = $this->drain();
        self::assertSame([], $translations);
        self::assertNull($this->store->broadcast(1));
        self::assertSame([100, 100], array_column($messages, 'id'));
        self::assertSame($this->preview($draft), $messages[1]['text']);
        self::assertStringNotContainsString('Объявление #', $messages[1]['text']);
        self::assertStringNotContainsString('Автор:', $messages[1]['text']);
        $rows = $messages[1]['options']['reply_markup']['inline_keyboard'];
        self::assertSame(['draft:send:1:2', 'draft:text:1:2', 'draft:subject:1:2', 'draft:cancel:1:2'], array_column(array_column($rows, 0), 'callback_data'));
        self::assertSame(Messages::text('RU', 'draft_send'), $rows[0][0]['text']);
        foreach (['text', 'subject'] as $field) {
            $this->click(100, 'draft:' . $field . ':1:' . $draft['version']);
            [$messages] = $this->drain();
            self::assertCount(2, $messages);
            self::assertSame([], $messages[0]['options']);
            self::assertSame($draft[$field], $messages[1]['text']);
            self::assertSame('pre', $messages[1]['options']['entities'][0]['type']);
            self::assertTrue($messages[1]['options']['reply_markup']['force_reply']);
            $this->say(100, 'Corrected ' . $field);
            $draft = $this->store->draft(1);
            self::assertSame('draft', $draft['status']);
            self::assertNull($this->store->session(100));
            self::assertNull($this->store->broadcast(1));
            [$preview] = $this->drain();
            self::assertSame($this->preview($draft), $preview[0]['text']);
        }
        $this->click(100, 'draft:send:1:' . $draft['version']);
        self::assertSame('Corrected text', $this->store->broadcast(1)['text']);
        self::assertSame('Corrected subject', $this->store->broadcast(1)['subject']);
    }

    public function testPreviewAndEditingPreserveLiteralTemplateMarkers(): void
    {
        $this->say(100, 'Private {subject} {signature} {text}');
        $this->say(100, 'Subject {text} {id}');
        $draft = $this->store->draft(1);
        [$messages] = $this->drain();
        self::assertSame($this->preview($draft), $messages[1]['text']);
        $this->click(100, 'draft:text:1:' . $draft['version']);
        [$messages] = $this->drain();
        self::assertSame($draft['text'], $messages[1]['text']);
        $this->click(100, 'draft:subject:1:' . $draft['version']);
        [$messages] = $this->drain();
        self::assertSame($draft['subject'], $messages[1]['text']);
    }

    public function testConfirmationSnapshotsAudienceAndLanguagesAndDeliversBothChannels(): void
    {
        $this->user(1, 'FR');
        $draft = $this->draft();
        [$messages, $translations, $mails] = $this->drain();
        self::assertSame([], $translations);
        self::assertSame([], $mails);
        self::assertNull($this->store->broadcast(1));
        $this->user(2, 'EN-GB');
        $this->user(4, 'RU', 'pending');
        $this->click(100, 'draft:send:1:' . $draft['version']);
        self::assertSame('approved', $this->store->draft(1)['status']);
        self::assertSame(100, $this->store->broadcast(1)['approved_by']);
        self::assertNull($this->store->session(100));
        $this->user(2, 'ES');
        $this->user(3, 'RU');
        [$messages, $translations, $mails] = $this->drain();
        self::assertCount(4, $translations);
        self::assertContains([$draft['text'], 'FR'], $translations);
        self::assertContains([$draft['subject'], 'EN-GB'], $translations);
        self::assertSame([1, 2], array_column(array_values(array_filter($messages, fn ($m) => $m['id'] < 99)), 'id'));
        self::assertContains(['private1@example.com', 'FR:' . $draft['subject'], 'FR:' . $draft['text']], $mails);
        self::assertContains(['private2@example.com', 'EN-GB:' . $draft['subject'], 'EN-GB:' . $draft['text']], $mails);
        self::assertCount(2, $mails);
        $staff = array_values(array_filter($messages, fn ($m) => $m['id'] >= 99));
        self::assertSame([100, 100], array_column($staff, 'id'));
        self::assertSame(Messages::text('RU', 'broadcast_started', ['id' => 1, 'recipients' => 2]), $staff[0]['text']);
        self::assertStringContainsString('Telegram: отправлено 2', $staff[1]['text']);
        self::assertStringContainsString('Email (принято почтовым сервисом): отправлено 2', $staff[1]['text']);
        self::assertStringNotContainsString('Private', json_encode($this->logs, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private', json_encode($this->logs, JSON_THROW_ON_ERROR));
    }

    public function testOnlyOwnerSuperadminCanSendAndOrdinaryAdminStillNeedsApproval(): void
    {
        $draft = $this->draft(99);
        $this->click(99, 'draft:send:1:' . $draft['version']);
        self::assertNull($this->store->broadcast(1));
        self::assertSame('draft', $this->store->draft(1)['status']);
        $this->click(100, 'draft:send:1:' . $draft['version']);
        self::assertNull($this->store->broadcast(1));
        $this->click(99, 'draft:submit:1:' . $draft['version']);
        self::assertSame('pending', $this->store->draft(1)['status']);
        self::assertNull($this->store->broadcast(1));
        $this->click(100, 'draft:send:1:' . $this->store->draft(1)['version']);
        self::assertNull($this->store->broadcast(1));
        $this->click(100, 'draft:approve:1:' . $this->store->draft(1)['version']);
        self::assertSame(100, $this->store->broadcast(1)['approved_by']);
        $this->say(100, 'Own text');
        $this->say(100, 'Own subject');
        $this->click(101, 'draft:send:2:' . $this->store->draft(2)['version']);
        self::assertNull($this->store->broadcast(2));
        self::assertSame('draft', $this->store->draft(2)['status']);
    }

    public function testMissingOrInvalidSubjectCannotSendAndCancelInvalidatesSend(): void
    {
        $this->say(100, 'Private original');
        $this->click(100, 'draft:send:1:1');
        self::assertNull($this->store->broadcast(1));
        self::assertSame('draft:subject', $this->store->session(100)['kind']);
        foreach (["Two\nlines", str_repeat('x', 201)] as $subject) {
            $this->say(100, $subject);
            self::assertSame('', $this->store->draft(1)['subject']);
            self::assertSame(1, $this->store->draft(1)['version']);
        }
        $this->say(100, 'Valid subject');
        $this->click(100, 'draft:cancel:1:2');
        $this->click(100, 'draft:send:1:' . $this->store->draft(1)['version']);
        self::assertNull($this->store->broadcast(1));
        self::assertNull($this->store->session(100));
        self::assertSame('cancelled', $this->store->draft(1)['status']);
    }

    public function testOldButtonsAndDuplicateUpdatesCannotSendTwice(): void
    {
        $draft = $this->draft();
        $this->click(100, 'draft:text:1:' . $draft['version']);
        $this->say(100, 'Corrected text');
        $this->click(100, 'draft:send:1:' . $draft['version']);
        self::assertNull($this->store->broadcast(1));
        $version = $this->store->draft(1)['version'];
        $update = $this->click(100, 'draft:send:1:' . $version);
        $approved = $this->store->draft(1);
        $this->bot->handle($update);
        self::assertSame($approved, $this->store->draft(1));
        $this->click(100, 'draft:send:1:' . $version);
        $this->click(100, 'draft:send:1:' . $approved['version']);
        $this->click(100, 'draft:approve:1:' . $approved['version']);
        self::assertSame($approved, $this->store->draft(1));
        self::assertNull($this->store->broadcast(2));
        [$messages] = $this->drain();
        self::assertCount(1, array_filter($messages, fn ($m) => str_contains($m['text'], 'запущена.')));
        self::assertCount(1, array_filter($messages, fn ($m) => str_contains($m['text'], 'Telegram:')));
    }

    public function testFailedBroadcastCreationRollsBackDraftAndUpdateReceipt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'superadmin-test-');
        try {
            $this->store = new SqliteStore($path);
            $this->store->migrate();
            $this->bot = new Kernel($this->store, [99], [100, 101]);
            $draft = $this->draft();
            $offset = $this->store->offset();
            $db = new \PDO('sqlite:' . $path);
            $db->exec("CREATE TRIGGER fail_broadcast BEFORE INSERT ON broadcasts BEGIN SELECT RAISE(ABORT, 'test insert failure'); END");
            $update = ['update_id' => ++$this->update, 'callback_query' => ['id' => 'send', 'from' => ['id' => 100], 'message' => ['chat' => ['id' => 100, 'type' => 'private']], 'data' => 'draft:send:1:' . $draft['version']]];
            try {
                $this->bot->handle($update);
                self::fail('The database trigger must abort broadcast creation.');
            } catch (\PDOException) {
                self::assertSame($draft, $this->store->draft(1));
                self::assertSame($offset, $this->store->offset());
                self::assertNull($this->store->broadcast(1));
            }
            $db->exec('DROP TRIGGER fail_broadcast');
            $this->bot->handle($update);
            self::assertSame('approved', $this->store->draft(1)['status']);
            self::assertSame(100, $this->store->broadcast(1)['approved_by']);
        } finally {
            unset($db, $this->bot, $this->store);
            gc_collect_cycles();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testLegacySubmitButtonSendsOwnDraftWithoutApprovalRequest(): void
    {
        $draft = $this->draft();
        $this->drain();
        $this->click(100, 'draft:submit:1:' . $draft['version']);
        self::assertSame('approved', $this->store->draft(1)['status']);
        self::assertSame(100, $this->store->broadcast(1)['approved_by']);
        [$messages] = $this->drain();
        self::assertSame([100, 100], array_column($messages, 'id'));
        self::assertStringContainsString('Получателей: 0', $messages[0]['text']);
        self::assertStringNotContainsString('согласование', implode('', array_column($messages, 'text')));
    }

    public function testTranslationFailureAndDetailedReportGoOnlyToSendingSuperadmin(): void
    {
        $this->user(1, 'FR');
        $draft = $this->draft();
        $this->drain();
        $this->click(100, 'draft:send:1:' . $draft['version']);
        [$messages, , $mails] = $this->drain($draft['text']);
        self::assertSame([], $mails);
        self::assertSame([100, 100, 100], array_column($messages, 'id'));
        self::assertStringContainsString('/retry 1', $messages[1]['text']);
        self::assertStringContainsString('Telegram: отправлено 0, пропущено 0, ошибки 1', $messages[2]['text']);
        self::assertStringNotContainsString('Private', json_encode($this->logs, JSON_THROW_ON_ERROR));
    }
}
