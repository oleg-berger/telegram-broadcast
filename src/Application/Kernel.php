<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Persist each accepted update and its effects in one short transaction. */
final class Kernel
{
    private Registration $registration;
    private Staff $staff;
    private AccountLinker $linker;
    private array $staffIds;

    public function __construct(private Store $store, array $adminIds, array $superadminIds = [], bool $adminsCanApprove = false, bool $adminsCanExport = false)
    {
        $this->staffIds = array_unique([...$adminIds, ...$superadminIds]);
        $this->linker = new AccountLinker($store, $superadminIds);
        $this->registration = new Registration($store, $adminsCanApprove ? $this->staffIds : $superadminIds, $this->linker);
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
            $staff = in_array($id, $this->staffIds, true);
            $data = (string) ($callback['data'] ?? '');
            $identity = $this->store->identity('telegram', (string) $id);
            $text = trim($message['text'] ?? '');
            if (!$staff && $identity === null && $text !== '/start' && !AccountLinker::isStopRequest($text)) {
                if ($callback) {
                    $this->store->queueCallback((string) $callback['id']);
                } elseif ($text === '/help') {
                    $this->store->queuePhoto($id, Messages::text('RU', 'help'));
                } else {
                    $this->store->queueReply($id, Messages::text('RU', 'start_hint'));
                }
                return;
            }
            if (!$staff || ($identity !== null && str_starts_with($data, 'link:confirm:'))) {
                $identity = $this->store->ensureIdentity('telegram', (string) $id);
            }
            if ($callback) {
                $this->store->queueCallback((string) $callback['id']);
                if (str_starts_with($data, 'link:confirm:')) {
                    if ($identity !== null) { $this->linker->confirm($identity, substr($data, 13)); }
                } elseif ($staff) {
                    $this->staff->callback($id, $data, $callback['from'] ?? []);
                } else {
                    $this->registration->callback($identity['user_id'], $data, $identity['id']);
                }
            } elseif ($staff) {
                $this->staff->message($id, $message);
            } else {
                $this->publicMessage($identity, $message);
            }
        });
    }

    /** Only normalized, private incoming messages cross this boundary. */
    public function handleWhatsApp(array $event): void
    {
        if (!$this->validWhatsAppEvent($event)) { return; }
        $this->store->transaction(function () use ($event): void {
            if (!$this->store->acceptChannelEvent('whatsapp', $event['event_id'], $event['sequence'], $event['created_at'])) { return; }
            if (!$this->store->acceptChannelEvent('whatsapp-message', $event['message_id'], 0)) { return; }
            if ($this->store->directoryEnabled()) { return; }
            $identity = $this->store->ensureIdentity('whatsapp', $event['address'], $event['verified_phone']);
            if ($identity['address_conflict'] ?? false) {
                $this->linker->addressConflict($identity, $event['body']);
                return;
            }
            $body = trim($event['body']);
            $menu = $this->store->menu($identity['id']);
            if (preg_match('/^[1-9][0-9]*$/D', $body) && ($menu['expires_at'] ?? 0) > time()
                && isset($menu['options'][(int) $body])) {
                $action = $menu['options'][(int) $body];
                if (str_starts_with($action, 'link:confirm:')) {
                    $user = $this->store->user($identity['user_id']);
                    $this->store->queueUserReply($user['id'], Messages::text($user['language'], 'link_invalid'),
                        ['reply_markup' => ['remove_keyboard' => true]], $identity['id']);
                    return;
                }
                if (str_starts_with($action, 'reg:') || str_starts_with($action, 'language:')) {
                    $this->registration->callback($identity['user_id'], $action, $identity['id']);
                    return;
                }
                $body = $action;
            }
            $this->publicMessage($identity, ['text' => $body]);
        });
    }

    private function publicMessage(array $identity, array $message): void
    {
        if (($identity['created'] ?? false) && trim($message['text'] ?? '') === '/start') {
            $this->store->queueUserReply($identity['user_id'], Messages::text('RU', 'intro'), [], $identity['id']);
        }
        $this->registration->message($identity['user_id'], $message, $identity['id']);
    }

    private function validWhatsAppEvent(array $event): bool
    {
        foreach (['event_id', 'message_id', 'address', 'body'] as $field) {
            if (!is_string($event[$field] ?? null) || ($field !== 'body' && ($event[$field] === '' || strlen($event[$field]) > 512))) { return false; }
        }
        if (!is_int($event['sequence'] ?? null) || $event['sequence'] < 0 || !is_int($event['created_at'] ?? null)
            || ($event['from_me'] ?? null) !== false || ($event['is_group'] ?? null) !== false
            || !array_key_exists('verified_phone', $event) || !mb_check_encoding($event['body'], 'UTF-8')
            || !preg_match('/^[1-9][0-9]{0,19}(?:@lid)?$/D', $event['address'])) { return false; }
        $phone = $event['verified_phone'];
        return ($phone === null && str_ends_with($event['address'], '@lid')) || (is_string($phone) && preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone)
            && (str_ends_with($event['address'], '@lid') || $phone === '+' . $event['address']));
    }
}
