<?php
declare(strict_types=1);
namespace Broadcast\Infrastructure\Contacts;

use Broadcast\Application\ContactDirectory;
use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\RecipientSnapshot;

final class LazyContactDirectory implements ContactDirectory
{
    private ?ContactDirectory $connection = null;
    /** @param \Closure():ContactDirectory $connect */
    public function __construct(private \Closure $connect) {}

    public function applyOperation(string $operationId, array $change): void
    {
        $this->invoke(static function (ContactDirectory $directory) use ($operationId, $change): void { $directory->applyOperation($operationId, $change); });
    }
    public function snapshot(string $snapshotId): RecipientSnapshot
    {
        return $this->invoke(static fn(ContactDirectory $directory): RecipientSnapshot => $directory->snapshot($snapshotId));
    }
    public function deliveryAllowed(int $contactId, string $channel): bool
    {
        return $this->invoke(static fn(ContactDirectory $directory): bool => $directory->deliveryAllowed($contactId, $channel));
    }
    private function invoke(\Closure $operation): mixed
    {
        try {
            $this->connection ??= ($this->connect)();
            return $operation($this->connection);
        } catch (ApiFailure $failure) {
            if ($failure->transient) { $this->connection = null; }
            throw $failure;
        } catch (\Throwable) {
            $this->connection = null;
            throw new ApiFailure('Contact directory unavailable.', true);
        }
    }
}
