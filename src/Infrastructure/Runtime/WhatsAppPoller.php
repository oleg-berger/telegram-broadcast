<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use Broadcast\Application\Kernel;
use Broadcast\Application\Store;
use Broadcast\Application\WhatsAppGateway;
use Broadcast\Domain\WhatsAppFailure;

final readonly class WhatsAppPoller
{
    /** @param ?list<string> $allowedRecipients Null is unrestricted; the local runtime always supplies its allowlist. */
    public function __construct(
        private Store $store,
        private WhatsAppGateway $whatsapp,
        private Kernel $kernel,
        private ?array $allowedRecipients = null,
    ) {}

    public function once(): int
    {
        $this->reconcileReceipts();
        $events = $this->whatsapp->events($this->store->channelCursor('whatsapp'));
        $count = 0;
        foreach ($events as $event) {
            if (!$this->validEnvelope($event)) {
                throw new WhatsAppFailure('transient');
            }
            if ($event['event'] === 'onmessage') {
                $this->message($event);
            } elseif ($event['event'] === 'onack') {
                $this->receipt($event);
            } else {
                $this->skip($event);
            }
            ++$count;
        }
        return $count;
    }

    private function message(array $event): void
    {
        $data = $event['data'];
        $address = self::address($data['chatId'] ?? null);
        if (($data['fromMe'] ?? null) !== false || ($data['isGroupMsg'] ?? null) !== false
            || ($data['isMedia'] ?? null) !== false || ($data['type'] ?? null) !== 'chat'
            || $address === null || !self::identifier($data['id'] ?? null)
            || !is_string($data['body'] ?? null) || !mb_check_encoding($data['body'], 'UTF-8')
            || mb_strlen($data['body'], 'UTF-8') > 65536 || str_contains($data['body'], "\0")) {
            $this->skip($event);
            return;
        }
        // A failed mapping request leaves this event unconsumed for replay; null is a valid opaque LID.
        $phone = $this->whatsapp->resolvePhone($address);
        if (!$this->allowed($phone)) {
            $this->skip($event);
            return;
        }
        $this->kernel->handleWhatsApp([
            'event_id' => $event['id'], 'sequence' => $event['sequence'], 'message_id' => $data['id'],
            'address' => $address, 'verified_phone' => $phone, 'body' => $data['body'],
            'from_me' => false, 'is_group' => false,
            'created_at' => is_int($data['t'] ?? null) ? $data['t'] : intdiv($event['createdAt'], 1000),
        ]);
    }

    private function receipt(array $event): void
    {
        $data = $event['data'];
        $address = self::address($data['chatId'] ?? null);
        $status = match ($data['type'] ?? null) { '' => 'delivered', 'read' => 'read', default => null };
        $ids = $data['messageIds'] ?? null;
        if ($address === null || $status === null || !is_array($ids) || !array_is_list($ids)
            || $ids === [] || count($ids) > 1000) {
            $this->skip($event);
            return;
        }
        foreach ($ids as $id) {
            if (!self::identifier($id)) {
                $this->skip($event);
                return;
            }
        }
        try {
            $phone = $this->whatsapp->resolvePhone($address);
        } catch (WhatsAppFailure) {
            $phone = null;
        }
        $this->store->transaction(function () use ($event, $ids, $status, $address, $phone): void {
            if (!$this->store->acceptChannelEvent('whatsapp', $event['id'], $event['sequence'])) {
                return;
            }
            if ($phone === null) {
                $this->store->deferWhatsAppReceipt($event['id'], $event['sequence'], $ids, $status, $address);
                return;
            }
            if (!$this->allowed($phone)) {
                return;
            }
            foreach ($ids as $id) {
                // Store also retains receipts that arrive before accepted send IDs are committed.
                $this->store->recordWhatsAppReceipt($id, $status, substr($phone, 1));
            }
        });
    }

    private function reconcileReceipts(): void
    {
        $resolved = [];
        foreach ($this->store->pendingWhatsAppReceipts(10) as $receipt) {
            $address = $receipt['address'];
            if (!array_key_exists($address, $resolved)) {
                try {
                    $resolved[$address] = $this->whatsapp->resolvePhone($address);
                } catch (WhatsAppFailure) {
                    // Keep durable work while new, unrelated events continue through the poller.
                    $resolved[$address] = null;
                }
            }
            $phone = $resolved[$address];
            if ($phone === null) {
                continue;
            }
            $this->store->transaction(function () use ($receipt, $phone): void {
                if ($this->allowed($phone)) {
                    foreach ($receipt['message_ids'] as $id) {
                        $this->store->recordWhatsAppReceipt($id, $receipt['status'], substr($phone, 1));
                    }
                }
                $this->store->completeWhatsAppReceipt($receipt['event_id']);
            });
        }
    }

    private function skip(array $event): void
    {
        // Persist only envelope identifiers for ignored media, own messages and secret QR events.
        $this->store->transaction(fn () => $this->store->acceptChannelEvent('whatsapp', $event['id'], $event['sequence']));
    }

    private function allowed(?string $phone): bool
    {
        return $this->allowedRecipients === null || ($phone !== null && in_array(substr($phone, 1), $this->allowedRecipients, true));
    }

    private function validEnvelope(mixed $event): bool
    {
        return is_array($event) && self::identifier($event['id'] ?? null)
            && is_int($event['sequence'] ?? null) && $event['sequence'] > 0
            && is_string($event['event'] ?? null) && $event['event'] !== ''
            && is_int($event['createdAt'] ?? null) && $event['createdAt'] > 0
            && is_array($event['data'] ?? null);
    }

    private static function address(mixed $address): ?string
    {
        if (!is_string($address)) {
            return null;
        }
        if (preg_match('/\A([1-9][0-9]{6,14})(?:@(?:s\.whatsapp\.net|c\.us))?\z/', $address, $match)) {
            return $match[1];
        }
        return preg_match('/\A[1-9][0-9]{0,19}@lid\z/', $address) ? $address : null;
    }

    private static function identifier(mixed $id): bool
    {
        return is_string($id) && $id !== '' && strlen($id) <= 128 && !preg_match('/[\x00-\x20\x7f]/', $id);
    }
}
