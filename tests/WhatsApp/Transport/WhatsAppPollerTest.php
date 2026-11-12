<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Transport;

use Broadcast\Application\Kernel;
use Broadcast\Application\WhatsAppGateway;
use Broadcast\Domain\WhatsAppFailure;
use Broadcast\Infrastructure\Runtime\WhatsAppPoller;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppPollerTest extends TestCase
{
    private SqliteStore $store;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->db = (new \ReflectionProperty($this->store, 'db'))->getValue($this->store);
    }

    public function testIncomingReplayAndDistinctTransportEventForSameMessageHaveOneEffect(): void
    {
        $event = $this->message(1, 'message-1', '77010000001@s.whatsapp.net');
        $gateway = new PollGatewayFake([$event]);
        $poller = $this->poller($gateway);
        self::assertSame(1, $poller->once());
        $identity = $this->store->identity('whatsapp', '77010000001');
        self::assertNotNull($identity);
        self::assertSame('+77010000001', $identity['verified_phone']);
        $jobs = (int) $this->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
        self::assertGreaterThan(0, $jobs);
        $gateway->batch = [$event, array_replace($event, ['id' => 'event-2', 'sequence' => 2])];
        $poller->once();
        self::assertSame($jobs, (int) $this->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        self::assertSame(2, $this->store->channelCursor('whatsapp'));
        self::assertSame([0, 1], $gateway->cursors);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM processed_updates')->fetchColumn());
    }

    public function testOpaqueLidUpgradesThroughVerifiedMappingWithoutCreatingAnotherPerson(): void
    {
        $gateway = new PollGatewayFake([$this->message(1, 'message-1', '123456789012345@lid')]);
        $this->poller($gateway)->once();
        $first = $this->store->identity('whatsapp', '123456789012345@lid');
        self::assertNotNull($first);
        self::assertNull($first['verified_phone']);
        $gateway->mapping = '+77010000001';
        $gateway->batch = [$this->message(2, 'message-2', '123456789012345@lid')];
        $this->poller($gateway)->once();
        $mapped = $this->store->identity('whatsapp', '77010000001');
        self::assertSame($first['id'], $mapped['id']);
        self::assertSame($first['user_id'], $mapped['user_id']);
        self::assertSame($first['id'], $this->store->identity('whatsapp', '123456789012345@lid')['id']);
    }

    public function testSkippedEventsAdvanceDurableCursorWithoutPersistingTheirBodies(): void
    {
        $own = $this->message(2, 'own', '77010000001@s.whatsapp.net');
        $own['data']['fromMe'] = true;
        $group = $this->message(3, 'group', '123456@g.us');
        $group['data']['isGroupMsg'] = true;
        $media = $this->message(4, 'media', '77010000001@s.whatsapp.net');
        $media['data']['isMedia'] = true;
        $media['data']['type'] = 'image';
        $gateway = new PollGatewayFake([
            $this->envelope(1, 'qrcode', ['qrcode' => 'private QR']), $own, $group, $media,
        ]);
        self::assertSame(4, $this->poller($gateway)->once());
        self::assertSame(4, $this->store->channelCursor('whatsapp'));
        self::assertSame(4, (int) $this->db->query('SELECT COUNT(*) FROM channel_events')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame([], $gateway->resolutions);
    }

    public function testMappingFailureDoesNotConsumeIncomingEventOrAdvancePastIt(): void
    {
        $gateway = new PollGatewayFake([$this->message(1, 'message-1', '123456789012345@lid')]);
        $gateway->mapping = new WhatsAppFailure('unavailable');
        try {
            $this->poller($gateway)->once();
            self::fail('Transient mapping failure must preserve incoming event.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('unavailable', $failure->category);
        }
        self::assertSame(0, $this->store->channelCursor('whatsapp'));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $gateway->mapping = '+77010000001';
        $this->poller($gateway)->once();
        self::assertSame(1, $this->store->channelCursor('whatsapp'));
        self::assertNotNull($this->store->identity('whatsapp', '77010000001'));
    }

    public function testUnmappedReceiptIsDurableDoesNotBlockMessagesAndReconcilesAfterRestart(): void
    {
        $gateway = new PollGatewayFake([
            $this->envelope(1, 'onack', ['chatId' => '123456789012345@lid', 'author' => '123456789012345@lid', 'messageIds' => ['sent-1'], 'type' => '', 't' => 1700000000]),
            $this->message(2, 'message-2', '77010000002@s.whatsapp.net'),
        ]);
        $this->poller($gateway)->once();
        self::assertSame(2, $this->store->channelCursor('whatsapp'));
        self::assertCount(1, $this->store->pendingWhatsAppReceipts());
        self::assertSame('delivered', $this->store->pendingWhatsAppReceipts()[0]['status']);
        self::assertNotNull($this->store->identity('whatsapp', '77010000002'));
        $gateway->mapping = '+77010000001';
        $gateway->batch = [];
        $this->poller($gateway)->once();
        self::assertSame([], $this->store->pendingWhatsAppReceipts());
        self::assertSame(['message_id' => 'sent-1', 'address' => '77010000001', 'status' => 'delivered'], $this->db->query('SELECT * FROM whatsapp_receipts')->fetch());
    }

    public function testReceiptMappingTransportFailureIsDeferredAndReadStatusIsNeverInvented(): void
    {
        $gateway = new PollGatewayFake([
            $this->envelope(1, 'onack', ['chatId' => '123456789012345@lid', 'messageIds' => ['sent-1'], 'type' => 'read', 't' => 1700000000]),
            $this->envelope(2, 'onack', ['chatId' => '77010000001@s.whatsapp.net', 'messageIds' => ['not-read'], 'type' => 'read-self', 't' => 1700000000]),
        ]);
        $gateway->mapping = new WhatsAppFailure('unavailable');
        $this->poller($gateway)->once();
        self::assertSame(2, $this->store->channelCursor('whatsapp'));
        self::assertSame('read', $this->store->pendingWhatsAppReceipts()[0]['status']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM whatsapp_receipts')->fetchColumn());
    }

    #[DataProvider('nativeReceiptBatchSizes')]
    public function testEntireNativeReceiptBatchIsDeferredWithoutBlockingLaterMessages(int $count): void
    {
        $ids = array_map(static fn (int $id): string => sprintf('sent-%04d', $id), range(1, $count));
        $gateway = new PollGatewayFake([
            $this->envelope(1, 'onack', ['chatId' => '123456789012345@lid', 'author' => '123456789012345@lid', 'messageIds' => $ids, 'type' => 'read', 't' => 1700000000]),
            $this->message(2, 'later-message', '77010000002@s.whatsapp.net'),
        ]);
        self::assertSame(2, $this->poller($gateway)->once());
        self::assertSame(2, $this->store->channelCursor('whatsapp'));
        self::assertNotNull($this->store->identity('whatsapp', '77010000002'));
        $pending = $this->store->pendingWhatsAppReceipts();
        self::assertCount(1, $pending);
        self::assertSame($ids, $pending[0]['message_ids']);

        $gateway->batch = [];
        $gateway->mapping = '+77010000001';
        $this->poller($gateway)->once();
        self::assertSame([], $this->store->pendingWhatsAppReceipts());
        self::assertSame($ids, $this->db->query('SELECT message_id FROM whatsapp_receipts ORDER BY message_id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(['read'], $this->db->query('SELECT DISTINCT status FROM whatsapp_receipts')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function nativeReceiptBatchSizes(): array
    {
        return [[101], [1000]];
    }

    public function testMalformedEnvelopeCannotSkipUnprocessedSequence(): void
    {
        $gateway = new PollGatewayFake([['sequence' => 1, 'event' => 'onmessage', 'data' => []]]);
        try {
            $this->poller($gateway)->once();
            self::fail('Malformed envelope must not advance cursor.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('transient', $failure->category);
        }
        self::assertSame(0, $this->store->channelCursor('whatsapp'));
    }

    public function testRegistrationFailureRollsBackIdentityAndCursorBeforeReplay(): void
    {
        $this->db->exec("CREATE TRIGGER fail_reply BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        $gateway = new PollGatewayFake([$this->message(1, 'message-1', '77010000001@s.whatsapp.net')]);
        try {
            $this->poller($gateway)->once();
            self::fail('Fixture must interrupt registration.');
        } catch (\PDOException) {
        }
        self::assertSame(0, $this->store->channelCursor('whatsapp'));
        self::assertNull($this->store->identity('whatsapp', '77010000001'));
        $this->db->exec('DROP TRIGGER fail_reply');
        $this->poller($gateway)->once();
        self::assertSame(1, $this->store->channelCursor('whatsapp'));
        self::assertNotNull($this->store->identity('whatsapp', '77010000001'));
    }

    public function testLocalInputAllowlistSkipsUnapprovedAndUnmappedSendersWithoutUserOrJob(): void
    {
        $gateway = new PollGatewayFake([
            $this->message(1, 'unapproved', '77010000002@s.whatsapp.net'),
            $this->message(2, 'unmapped', '123456789012345@lid'),
        ]);
        $poller = new WhatsAppPoller($this->store, $gateway, new Kernel($this->store, [], [900]), ['77010000001']);
        self::assertSame(2, $poller->once());
        self::assertSame(2, $this->store->channelCursor('whatsapp'));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $gateway->batch = [$this->message(3, 'approved', '77010000001@s.whatsapp.net')];
        $poller->once();
        self::assertNotNull($this->store->identity('whatsapp', '77010000001'));
    }

    public function testEmptyLocalInputAllowlistFailsClosed(): void
    {
        $gateway = new PollGatewayFake([$this->message(1, 'unapproved', '77010000001@s.whatsapp.net')]);
        $poller = new WhatsAppPoller($this->store, $gateway, new Kernel($this->store, [], [900]), []);
        $poller->once();
        self::assertSame(1, $this->store->channelCursor('whatsapp'));
        self::assertNull($this->store->identity('whatsapp', '77010000001'));
    }

    public function testLocalInputAllowlistDoesNotStoreUnapprovedReceiptsAfterMapping(): void
    {
        $gateway = new PollGatewayFake([
            $this->envelope(1, 'onack', ['chatId' => '77010000002@s.whatsapp.net', 'messageIds' => ['sent-1'], 'type' => 'read', 't' => 1700000000]),
            $this->envelope(2, 'onack', ['chatId' => '123456789012345@lid', 'messageIds' => ['sent-2'], 'type' => 'read', 't' => 1700000000]),
        ]);
        $poller = new WhatsAppPoller($this->store, $gateway, new Kernel($this->store, [], [900]), ['77010000001']);
        $poller->once();
        self::assertSame(2, $this->store->channelCursor('whatsapp'));
        self::assertCount(1, $this->store->pendingWhatsAppReceipts());
        $gateway->batch = [];
        $gateway->mapping = '+77010000002';
        $poller->once();
        self::assertSame([], $this->store->pendingWhatsAppReceipts());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM whatsapp_receipts')->fetchColumn());
    }

    public function testReceiptWriteFailureRollsBackCursorBeforeReplay(): void
    {
        $this->db->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON whatsapp_receipts BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        $gateway = new PollGatewayFake([$this->envelope(1, 'onack', ['chatId' => '77010000001@s.whatsapp.net', 'messageIds' => ['sent-1'], 'type' => 'read', 't' => 1700000000])]);
        try {
            $this->poller($gateway)->once();
            self::fail('Fixture must interrupt receipt write.');
        } catch (\PDOException) {
        }
        self::assertSame(0, $this->store->channelCursor('whatsapp'));
        $this->db->exec('DROP TRIGGER fail_receipt');
        $this->poller($gateway)->once();
        self::assertSame(1, $this->store->channelCursor('whatsapp'));
        self::assertSame('read', $this->db->query('SELECT status FROM whatsapp_receipts')->fetchColumn());
    }

    private function poller(PollGatewayFake $gateway): WhatsAppPoller
    {
        return new WhatsAppPoller($this->store, $gateway, new Kernel($this->store, [], [900]));
    }

    private function message(int $sequence, string $id, string $address): array
    {
        return $this->envelope($sequence, 'onmessage', ['id' => $id, 'from' => $address, 'author' => $address, 'chatId' => $address,
            'fromMe' => false, 'isGroupMsg' => false, 'body' => '/start', 'type' => 'chat', 'isMedia' => false, 't' => 1700000000]);
    }

    private function envelope(int $sequence, string $event, array $data): array
    {
        return ['sequence' => $sequence, 'id' => 'event-' . $sequence, 'session' => 'whatsapp-test', 'event' => $event, 'createdAt' => 1700000000000, 'data' => $data];
    }
}

final class PollGatewayFake implements WhatsAppGateway
{
    public array $cursors = [];
    public array $resolutions = [];
    public string|\Throwable|null $mapping = null;

    public function __construct(public array $batch) {}
    public function connected(): bool { return true; }
    public function sendText(string $address, string $text): string { throw new \LogicException('Poller cannot send.'); }
    public function events(int $cursor, int $limit = 100): array { $this->cursors[] = $cursor; return $this->batch; }
    public function resolvePhone(string $address): ?string
    {
        $this->resolutions[] = $address;
        if (str_ends_with($address, '@lid')) {
            if ($this->mapping instanceof \Throwable) { throw $this->mapping; }
            return $this->mapping;
        }
        return '+' . explode('@', $address)[0];
    }
}
