<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Infrastructure\SqliteStore;
use Broadcast\Domain\RecipientSnapshot;
use Broadcast\Application\ContactDirectory;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use PHPUnit\Framework\TestCase;

final class DirectoryBroadcastTest extends TestCase
{
    public function testApprovalWaitsForWholeAudienceAndPublishesOnlyOnce(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $draft = $store->createDraft(99, 'News');
        $store->saveDraft($draft['id'], 1, ['subject' => 'Subject', 'status' => 'pending']);
        $broadcast = $store->approveDraft($draft['id'], 2, 99);
        $store->refreshBroadcasts();
        self::assertSame('preparing_audience', $store->broadcast($broadcast['id'])['status']);
        $job = $store->nextJob(time());
        self::assertSame('audience_snapshot', $job['kind']);
        $snapshot = new RecipientSnapshot('test-snapshot', time(), [
            ['contact_id' => 7, 'telegram_user_id' => null, 'phone' => '+447700900001', 'channel' => 'whatsapp', 'address' => '447700900001', 'language' => 'ES'],
        ]);
        self::assertTrue($store->publishAudience($broadcast['id'], $snapshot));
        self::assertFalse($store->publishAudience($broadcast['id'], $snapshot));
        self::assertSame('preparing', $store->broadcast($broadcast['id'])['status']);
        $identity = $store->identity('whatsapp', '447700900001');
        self::assertNotNull($identity);
        self::assertCount(1, $store->identities($identity['user_id']));
        self::assertTrue($store->unsubscribeUser($identity['user_id']));
        self::assertTrue($store->directoryBlocked(7));
    }

    public function testDirectoryModeDoesNotStartWhatsAppRegistration(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $kernel = new \Broadcast\Application\Kernel($store, [99], [99]);
        $kernel->handleWhatsApp(['event_id'=>'e1','message_id'=>'m1','address'=>'447700900001','body'=>'/start','sequence'=>1,'created_at'=>time(),'from_me'=>false,'is_group'=>false,'verified_phone'=>'+447700900001']);
        self::assertNull($store->identity('whatsapp', '447700900001'));
        self::assertSame(1, $store->channelCursor('whatsapp'));
        self::assertNull($store->nextJob(time()));
    }

    public function testWorkerSnapshotsOnceAndChecksChannelBeforeExternalSend(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $store->ensureIdentity('telegram', '55');
        $directory = $this->createMock(ContactDirectory::class);
        $directory->expects(self::once())->method('snapshot')->willReturn(new RecipientSnapshot('snap', time(), [
            ['contact_id'=>2,'telegram_user_id'=>55,'phone'=>'+447700900001','channel'=>'telegram','address'=>'55','language'=>'FR'],
        ]));
        $directory->expects(self::once())->method('deliveryAllowed')->with(2, 'telegram')->willReturn(false);
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id): void { self::assertNotSame(55, $id); });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(static fn(string $text): string => 'FR ' . $text);
        $draft = $store->createDraft(99,'News');
        $store->saveDraft($draft['id'],1,['subject'=>'Subject','status'=>'pending']);
        $store->approveDraft($draft['id'],2,99);
        $worker = new Worker($store, $telegram, $translator, directory: $directory);
        $now = time();
        for ($i=0; $i<30 && $worker->tick($now+$i); ++$i) {}
        self::assertLessThan(30, $i);
        self::assertSame(1,$store->broadcast(1)['reported']);
    }
}
