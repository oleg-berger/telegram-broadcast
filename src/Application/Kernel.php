<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Persist each accepted update and its effects in one short transaction. */
final class Kernel
{
    private Registration $registration;
    private Staff $staff;
    private array $staffIds;

    public function __construct(private Store $store, array $adminIds, array $superadminIds = [], bool $adminsCanApprove = false, bool $adminsCanExport = false)
    {
        $this->staffIds = array_unique([...$adminIds, ...$superadminIds]);
        $this->registration = new Registration($store, $adminsCanApprove ? $this->staffIds : $superadminIds);
        $this->staff = new Staff($store, $superadminIds, $adminsCanApprove, $adminsCanExport);
    }

    public function handle(array $update): void
    {
        if (!isset($update['update_id']) || !is_int($update['update_id'])) { return; }
        // Commit the update receipt and all resulting jobs together, before polling advances its offset.
        $this->store->transaction(function () use ($update): void {
            $callback = $update['callback_query'] ?? null;
            $message = $callback['message'] ?? ($update['message'] ?? null);
            // Callback dates belong to the original button message, not the later click.
            $sourceDate = is_int($message['date'] ?? null) ? $message['date'] : null;
            if (!$this->store->acceptUpdate($update['update_id'], $sourceDate)) { return; }
            if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'private') { return; }
            $id = (int) ($callback['from']['id'] ?? $message['from']['id'] ?? 0);
            if ($id <= 0 || (int) $message['chat']['id'] !== $id) { return; }
            $flow = in_array($id, $this->staffIds, true) ? $this->staff : $this->registration;
            if ($callback) {
                $this->store->queueCallback((string) $callback['id']);
                if ($flow === $this->staff) {
                    $this->staff->callback($id, (string) ($callback['data'] ?? ''), $callback['from'] ?? []);
                } else {
                    $flow->callback($id, (string) ($callback['data'] ?? ''));
                }
            } else {
                $flow->message($id, $message);
            }
        });
    }
}
