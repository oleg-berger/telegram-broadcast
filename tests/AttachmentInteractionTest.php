<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\AttachmentStorage;
use Broadcast\Application\AttachmentTelegramGateway;
use Broadcast\Application\Kernel;
use Broadcast\Application\Messages;
use Broadcast\Application\TelegramKeyboardGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class AttachmentInteractionTest extends TestCase
{
    private SqliteStore $store;
    private Kernel $bot;
    private int $update = 0;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->bot = new Kernel($this->store, [99], [100]);
        $this->say(['text' => 'News']);
        $this->say(['text' => 'Subject']);
        $this->db()->exec("DELETE FROM jobs");
    }

    private function db(): \PDO { return (new \ReflectionProperty($this->store, 'db'))->getValue($this->store); }

    private function say(array $message): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'message' => [
            'from' => ['id' => 100], 'chat' => ['id' => 100, 'type' => 'private'],
        ] + $message]);
    }

    private function click(string $data, int $actor = 100): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'callback_query' => [
            'id' => (string) $this->update, 'from' => ['id' => $actor], 'data' => $data,
            'message' => ['message_id' => 77, 'chat' => ['id' => $actor, 'type' => 'private']],
        ]]);
    }

    private function files(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->say(['document' => ['file_id' => 'file-' . $i, 'file_name' => 'file.pdf', 'file_size' => 3]]);
        }
        $this->db()->exec("UPDATE jobs SET status='done' WHERE kind='attachment_download'");
    }

    private function ready(): void
    {
        foreach ($this->store->draftAttachments(1) as $file) {
            $this->store->completeAttachment($file['id'], new Attachment(str_repeat('a', 64), 'file.pdf', 'application/pdf', 3, hash('sha256', 'pdf')));
        }
    }

    private function lastPayload(string $kind): array
    {
        $query = $this->db()->prepare('SELECT payload FROM jobs WHERE kind=? ORDER BY id DESC LIMIT 1');
        $query->execute([$kind]);
        return json_decode($query->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testDefaultColorForIndividualDeleteButtons(): void
    {
        $this->files(2);
        $this->click('draft:removefiles:1:4');
        $rows = $this->lastPayload('edit_keyboard')['rows'];
        self::assertCount(3, $rows);
        foreach ($rows as $row) { self::assertArrayNotHasKey('style', $row[0]); }
    }

    public function testBackRestoresSameKeyboardWithoutSendingFilesIncludingLegacyBack(): void
    {
        $this->files(2);
        $this->ready();
        $this->store->markDraftPreviewed(1, 4, 100);
        $this->say(['text' => '/draft 1']);
        $expected = $this->lastPayload('draft_preview')['options']['reply_markup']['inline_keyboard'];
        $this->db()->exec('DELETE FROM jobs');
        foreach (['back', 'finish'] as $action) { $this->click('draft:' . $action . ':1:4'); }
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind IN ('message','draft_preview')")->fetchColumn());
        $telegram = $this->createMockForIntersectionOfInterfaces([AttachmentTelegramGateway::class, TelegramKeyboardGateway::class]);
        $telegram->expects(self::exactly(2))->method('editKeyboard')->with(100, 77, $expected);
        $telegram->expects(self::never())->method('sendMessage');
        $telegram->expects(self::never())->method('sendAttachment');
        $worker = new Worker($this->store, $telegram, $this->createMock(Translator::class));
        for ($i = 0; $i < 10; ++$i) { $worker->tick(time() + $i); }
        $this->click('draft:back:1:4', 99);
        $this->store->saveDraft(1, 4, ['text' => 'Changed']);
        $this->click('draft:back:1:4');
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='edit_keyboard'")->fetchColumn());
    }

    public function testDoneButtonOnConfirmationOpensFullPreview(): void
    {
        $this->files(2);
        $this->ready();
        $notice = (int) $this->db()->query("SELECT id FROM jobs WHERE kind='attachment_notice'")->fetchColumn();
        $this->store->finishAttachmentNotice($notice, time() + 5);
        $message = $this->lastPayload('message');
        self::assertSame("В черновик #1 добавлено файлов: 2.\nОтправьте остальные или нажмите кнопку «Готово».", $message['text']);
        $button = $message['options']['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('Готово', $button['text']);
        self::assertSame('success', $button['style']);
        $this->click($button['callback_data']);
        $this->click($button['callback_data']);
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='draft_preview'")->fetchColumn());
        $telegram = $this->createMock(AttachmentTelegramGateway::class);
        $telegram->expects(self::exactly(2))->method('sendAttachment');
        $telegram->expects(self::exactly(3))->method('sendMessage');
        $storage = $this->createMock(AttachmentStorage::class);
        $storage->method('path')->willReturn('local');
        $worker = new Worker($this->store, $telegram, $this->createMock(Translator::class), attachments: $storage);
        for ($i = 0; $i < 20; ++$i) { $worker->tick(time() + 10 + $i); }
        self::assertTrue($this->store->draftPreviewed(1, 4, 100));
        $this->files(1);
        $this->click($button['callback_data']);
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='draft_preview'")->fetchColumn());
    }

    public function testAddFilesHelpHasRequestedTextAndWorkingDoneButton(): void
    {
        $this->click('draft:files:1:2');
        $message = $this->lastPayload('message');
        self::assertSame('Добавьте фотографии или документы (до 10 файлов, каждый до 20 МБ). Отдельные подписи не используются. Затем нажмите «Готово».', $message['text']);
        $button = $message['options']['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('Готово', $button['text']);
        self::assertSame('success', $button['style']);
        $this->click($button['callback_data']);
        self::assertStringContainsString('Subject', $this->lastPayload('message')['text']);
    }

    public function testUncertainPreviewOffersCancelWithoutRetryingTheFile(): void
    {
        $this->files(2);
        $this->ready();
        $this->db()->exec('DELETE FROM jobs');
        $this->click('draft:done:1:4');
        $telegram = $this->createMock(AttachmentTelegramGateway::class);
        $telegram->expects(self::once())->method('sendAttachment')
            ->willThrowException(new ApiFailure('Timed out', transient: true, uncertain: true));
        $storage = $this->createMock(AttachmentStorage::class);
        $storage->method('path')->willReturn('local');
        $worker = new Worker($this->store, $telegram, $this->createMock(Translator::class), attachments: $storage);
        for ($i = 0; $i < 20; ++$i) { $worker->tick(time() + $i); }
        self::assertSame('unknown', $this->db()->query("SELECT status FROM jobs WHERE kind='draft_preview'")->fetchColumn());
        self::assertFalse($this->store->draftPreviewed(1, 4, 100));
        $notice = $this->lastPayload('message');
        self::assertStringContainsString('/draft 1', $notice['text']);
        $this->click($notice['options']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertSame('cancelled', $this->store->draft(1)['status']);
    }

    public function testCancelCommandDuringAttachmentInputCancelsTheDraft(): void
    {
        $this->files(1);
        $this->say(['text' => '/cancel']);
        self::assertSame('cancelled', $this->store->draft(1)['status']);
        self::assertNull($this->store->session(100));
    }

    public function testBatchWaitsForEveryDownloadAndDoneAutomaticallyShowsPreview(): void
    {
        $this->files(3);
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='message'")->fetchColumn());
        $this->say(['text' => '/done']);
        $this->say(['text' => '/done']);
        $events = [];
        $telegram = $this->createMock(AttachmentTelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function ($id, $text, $options) use (&$events): void { $events[] = ['text', $text, $options]; });
        $telegram->method('sendAttachment')->willReturnCallback(static function () use (&$events): void { $events[] = ['file']; });
        $storage = $this->createMock(AttachmentStorage::class);
        $storage->method('path')->willReturn('local');
        $worker = new Worker($this->store, $telegram, $this->createMock(Translator::class), attachments: $storage);
        $now = time() + 5;
        for ($i = 0; $i < 10; ++$i) { $worker->tick($now + $i); }
        self::assertSame([], $events);
        $this->ready();
        for ($i = 0; $i < 30; ++$i) { $worker->tick($now + 20 + $i); }
        self::assertSame(['text', 'text', 'file', 'file', 'file', 'text'], array_column($events, 0), json_encode($events, JSON_UNESCAPED_UNICODE));
        self::assertSame(Messages::text('RU', 'attachments_added', ['count' => 3, 'id' => 1]), $events[0][1]);
        $buttons = $events[5][2]['reply_markup']['inline_keyboard'];
        self::assertCount(6, $buttons);
        self::assertSame('success', $buttons[0][0]['style']);
        self::assertSame('danger', $buttons[5][0]['style']);
        self::assertSame('draft:removefiles:1:5', $buttons[5][0]['callback_data']);
        self::assertTrue($this->store->draftPreviewed(1, 5, 100));
    }

    public function testMenuEditsClickedMessageAndRemovalQueuesNewPreview(): void
    {
        $this->files(2);
        $this->ready();
        $this->click('draft:removefiles:1:4');
        $job = $this->db()->query("SELECT payload FROM jobs WHERE kind='edit_keyboard' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertNotFalse($job);
        $payload = json_decode($job, true);
        self::assertSame(77, $payload['message_id']);
        self::assertCount(3, $payload['rows']);
        self::assertSame('draft:remove:1:4:1', $payload['rows'][0][0]['callback_data']);
        self::assertSame('draft:remove:1:4:2', $payload['rows'][1][0]['callback_data']);
        $this->click('draft:remove:1:4:1');
        self::assertCount(1, $this->store->draftAttachments(1));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='draft_preview'")->fetchColumn());
        $this->click('draft:remove:1:4:2');
        self::assertCount(1, $this->store->draftAttachments(1));
        $this->click('draft:removefiles:1:5', 99);
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='edit_keyboard'")->fetchColumn());
        $this->click('draft:removefiles:1:5');
        $payload = json_decode($this->db()->query("SELECT payload FROM jobs WHERE kind='edit_keyboard' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        self::assertSame(Messages::text('RU', 'attachment_remove', ['position' => 1]), $payload['rows'][0][0]['text']);
        self::assertSame('draft:remove:1:5:2', $payload['rows'][0][0]['callback_data']);
    }

    public function testMenuWorkerEditsInPlaceAndSkipsOutdatedChanges(): void
    {
        $this->files(2);
        $this->ready();
        $this->click('draft:removefiles:1:4');
        $edits = [];
        $filesSent = 0;
        $telegram = $this->createMockForIntersectionOfInterfaces([AttachmentTelegramGateway::class, TelegramKeyboardGateway::class]);
        $telegram->method('editKeyboard')->willReturnCallback(static function ($chat, $message, $rows) use (&$edits): void { $edits[] = [$chat, $message, $rows]; });
        $telegram->method('sendAttachment')->willReturnCallback(static function () use (&$filesSent): void { ++$filesSent; });
        $storage = $this->createMock(AttachmentStorage::class);
        $storage->method('path')->willReturn('local');
        $worker = new Worker($this->store, $telegram, $this->createMock(Translator::class), attachments: $storage);
        $now = time() + 5;
        for ($i = 0; $i < 20; ++$i) { $worker->tick($now + $i); }
        self::assertCount(1, $edits);
        self::assertSame([100, 77], array_slice($edits[0], 0, 2));
        self::assertCount(3, $edits[0][2]);
        $this->click('draft:remove:1:4:1');
        for ($i = 0; $i < 20; ++$i) { $worker->tick($now + 30 + $i); }
        self::assertSame([100, 77, []], $edits[1]);
        self::assertSame(1, $filesSent);
        self::assertTrue($this->store->draftPreviewed(1, 5, 100));
        $this->click('draft:removefiles:1:5');
        $this->store->saveDraft(1, 5, ['status' => 'cancelled']);
        for ($i = 0; $i < 10; ++$i) { $worker->tick($now + 60 + $i); }
        self::assertCount(2, $edits);
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='edit_keyboard' AND status='skipped'")->fetchColumn());
    }

    public function testBatchCountsOnlySuccessfulNewFilesAndIgnoresCancelledDraft(): void
    {
        $this->files(2);
        $this->store->completeAttachment(1, new Attachment(str_repeat('a', 64), 'file.pdf', 'application/pdf', 3, hash('sha256', 'pdf')));
        $this->db()->exec("UPDATE jobs SET status='failed' WHERE kind='attachment_download' AND json_extract(payload,'$.attachment_id')=2");
        $notice = (int) $this->db()->query("SELECT id FROM jobs WHERE kind='attachment_notice'")->fetchColumn();
        $this->store->finishAttachmentNotice($notice, time() + 5);
        $payload = json_decode($this->db()->query("SELECT payload FROM jobs WHERE kind='message' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        self::assertSame(Messages::text('RU', 'attachments_added', ['count' => 1, 'id' => 1]), $payload['text']);
        $this->files(1);
        $notice = (int) $this->db()->query("SELECT id FROM jobs WHERE kind='attachment_notice' AND status='pending'")->fetchColumn();
        $this->ready();
        $this->store->finishAttachmentNotice($notice, time() + 5);
        $payload = json_decode($this->db()->query("SELECT payload FROM jobs WHERE kind='message' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        self::assertSame(Messages::text('RU', 'attachments_added', ['count' => 1, 'id' => 1]), $payload['text']);
        $this->files(1);
        $this->store->saveDraft(1, $this->store->draft(1)['version'], ['status' => 'cancelled']);
        $notice = (int) $this->db()->query("SELECT id FROM jobs WHERE kind='attachment_notice' AND status='pending'")->fetchColumn();
        $this->store->finishAttachmentNotice($notice, time() + 5);
        self::assertSame(2, (int) $this->db()->query("SELECT COUNT(*) FROM jobs WHERE kind='message'")->fetchColumn());
        self::assertSame('skipped', $this->db()->query("SELECT status FROM jobs WHERE id=$notice")->fetchColumn());
    }
}
