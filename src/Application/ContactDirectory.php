<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\RecipientSnapshot;

interface ContactDirectory
{
    /** Apply an import, Telegram profile, or subscription change exactly once. */
    public function applyOperation(string $operationId, array $change): void;
    public function snapshot(string $snapshotId): RecipientSnapshot;
    public function deliveryAllowed(int $contactId, string $channel): bool;
}
