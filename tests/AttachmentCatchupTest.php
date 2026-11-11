<?php
declare(strict_types=1);
namespace Broadcast\Tests;

use Broadcast\Application\AttachmentMailGateway;
use Broadcast\Application\AttachmentStorage;
use Broadcast\Application\AttachmentTelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\Attachment;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class AttachmentCatchupTest extends TestCase
{
    public function testNewApprovedUserGetsOriginalFilesOnceWithTheirOwnTranslation(): void
    {
        $store = new SqliteStore(':memory:');
        $store->migrate();
        $draft = $store->createDraft(99, 'News');
        self::assertTrue($store->stageDraftAttachment($draft['id'], 1, 'test-file', 'report.pdf', 3));
        $store->completeAttachment(1, new Attachment(str_repeat('a', 64), 'report.pdf', 'application/pdf', 3, hash('sha256', 'pdf')));
        $store->saveDraft(1, 2, ['subject' => 'Subject', 'status' => 'pending']);
        $store->markDraftPreviewed(1, 3, 100);
        self::assertNotNull($store->approveDraft(1, 3, 100));
        $store->refreshBroadcasts();
        $store->saveUser(['id'=>1,'language'=>'FR','step'=>'review','name'=>'N','company'=>'C','country'=>'FR','phone'=>'+33600000001','email'=>'one@example.test','status'=>'approved','subscribed'=>1,'completed'=>1,'choosing_language'=>0]);
        self::assertTrue($store->addCatchup(1, 'FR'));
        $store->addCatchup(1, 'FR');
        $sent = [];
        $telegram = $this->createMock(AttachmentTelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(function ($id, $text) use (&$sent): void { if ($id === 1) { $sent[] = $text; } });
        $telegram->expects(self::once())->method('sendAttachment')->willReturnCallback(function ($id, $file) use (&$sent): void { self::assertSame(1, $id); $sent[] = $file->displayName; });
        $mail = $this->createMock(AttachmentMailGateway::class);
        $mail->expects(self::once())->method('sendWithAttachments')->willReturnCallback(function ($to, $subject, $body, $files): void {
            self::assertSame('FR:Subject', $subject);
            self::assertSame('FR:News', $body);
            self::assertSame('report.pdf', $files[0]['attachment']->displayName);
        });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(fn ($text, $language) => $language . ':' . $text);
        $storage = $this->createMock(AttachmentStorage::class);
        $storage->method('path')->willReturn('fake-local-path');
        $worker = new Worker($store, $telegram, $translator, mail: $mail, attachments: $storage);
        $now = time();
        for ($i = 0; $i < 60 && $worker->tick($now + $i); ++$i) {}
        self::assertSame(['FR:News', 'report.pdf'], $sent);
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        self::assertSame(['done', 'done'], $db->query("SELECT status FROM jobs WHERE kind='delivery' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }
}
