<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Matching offers a proposal; only the original account or an audited staff decision attaches it. */
final class AccountLinker
{
    public function __construct(private Store $store, private array $superadmins) {}

    public function offer(array $user, int $identityId, string $field): void
    {
        $identity = $this->store->identityById($identityId);
        $manual = $field === 'email' || ($identity['channel'] === 'whatsapp' && $identity['verified_phone'] === null);
        $this->reply($user, $identityId, $manual ? 'link_manual_offer' : 'link_offer');
    }

    public function message(array $user, array $identity, string $text): bool
    {
        if (preg_match('/^\/confirm ([A-Za-z0-9_-]{43})$/D', $text, $match)) {
            $this->confirm($identity, $match[1]);
            return true;
        }
        if (!in_array($text, ['/link', '/linkhelp'], true)) { return false; }
        $phone = $user['phone'] !== '' ? $this->store->contactOwner('phone', $user['phone'], $user['id']) : null;
        $email = $user['email'] !== '' ? $this->store->contactOwner('email', $user['email'], $user['id']) : null;
        $target = $phone ?? $email;
        if ($target === null) {
            $this->reply($user, $identity['id'], 'link_unavailable');
            return true;
        }
        $manual = $text === '/linkhelp' || $phone === null || ($email !== null && $email['id'] !== $target['id'])
            || ($identity['channel'] === 'whatsapp' && $identity['verified_phone'] === null)
            || ($user['submitted_at'] ?? null) !== null;
        $request = $this->store->createLinkRequest($identity['id'], $target['id'], time(), $manual);
        if ($request === null) {
            $this->reply($user, $identity['id'], 'link_unavailable');
            return true;
        }
        if ($manual) {
            foreach ($this->superadmins as $actor) {
                $this->store->queueReply($actor, Messages::text('RU', 'link_staff_pending', ['id' => $request['id']]));
            }
            $this->reply($user, $identity['id'], 'link_manual_pending');
            return true;
        }
        $confirmationIdentity = $this->store->identityById($request['confirm_identity_id']);
        if ($confirmationIdentity['channel'] === 'whatsapp') {
            // Numeric menu choices cannot bind a reply to the prompt the user intended.
            $this->store->queueUserReply($target['id'], Messages::text($target['language'], 'link_confirm_code', ['token' => $request['token']]),
                ['reply_markup' => ['remove_keyboard' => true]], $confirmationIdentity['id']);
        } else {
            $options = ['reply_markup' => ['inline_keyboard' => [[[
                'text' => Messages::text($target['language'], 'link_confirm_button'),
                'callback_data' => 'link:confirm:' . $request['token'],
            ]]]]];
            $this->store->queueUserReply($target['id'], Messages::text($target['language'], 'link_confirm_prompt'), $options, $confirmationIdentity['id']);
        }
        $this->reply($user, $identity['id'], 'link_pending');
        return true;
    }

    public function confirm(array $identity, string $token): void
    {
        $result = $this->store->confirmLink($token, $identity['id'], time());
        if ($result === null) {
            $this->reply($this->store->user($identity['user_id']), $identity['id'], 'link_invalid');
            return;
        }
        $this->completed($result);
    }

    public function addressConflict(array $identity, string $text): void
    {
        if (self::isStopRequest($text)) {
            // A proven transport alias authorizes stopping messages, but not merging profiles.
            foreach ([$identity['user_id'], $identity['conflict_user_id']] as $userId) {
                $this->store->unsubscribeUser($userId);
                foreach ($this->superadmins as $actor) {
                    $this->store->queueReply($actor, Messages::text('RU', 'stop_staff', ['id' => $userId]));
                }
            }
            $user = $this->store->user($identity['conflict_user_id']);
            foreach ($this->store->identities($user['id']) as $targetIdentity) {
                if ($targetIdentity['channel'] === 'whatsapp') {
                    $key = $user['status'] === 'unsubscribed' ? 'stop_complete' : 'stop_pending';
                    $this->reply($user, $targetIdentity['id'], $key);
                }
            }
            return;
        }
        $request = $this->store->createLinkRequest($identity['id'], $identity['conflict_user_id'], time(), true);
        if ($request !== null) {
            foreach ($this->superadmins as $actor) {
                $this->store->queueReply($actor, Messages::text('RU', 'link_staff_pending', ['id' => $request['id']]));
            }
        }
        $this->reply($this->store->user($identity['user_id']), $identity['id'], 'link_manual_pending');
    }

    public function completed(array $result): void
    {
        $user = $this->store->user($result['user_id']);
        $this->store->queueUserReply($user['id'], Messages::text($user['language'], 'link_complete'), ['reply_markup' => ['remove_keyboard' => true]]);
        if ($user['status'] === 'approved') {
            $this->store->addChannelCatchup($user['id'], $user['language'], $result['channel']);
        }
    }

    public function stop(array $user, int $identityId, string $text): bool
    {
        if (!self::isStopRequest($text)) { return false; }
        $stopped = $this->store->unsubscribeUser($user['id']) || $user['status'] === 'unsubscribed';
        foreach ($this->superadmins as $actor) {
            $this->store->queueReply($actor, Messages::text('RU', 'stop_staff', ['id' => $user['id']]));
        }
        $this->reply($user, $identityId, $stopped ? 'stop_complete' : 'stop_pending');
        return true;
    }

    public static function isStopRequest(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['’', '!', '.'], ["'", '', ''], $text);
        $stops = ['/stop', 'stop', 'unsubscribe', 'stop messages', 'stop sending me messages', 'please stop sending me messages',
            'стоп', 'отписаться', 'отпишите меня', 'удалите меня из рассылки', 'больше не пишите', 'не присылайте сообщения', 'не отправляйте мне сообщения', 'прекратите рассылку',
            'baja', 'cancelar suscripción', 'dejar de recibir mensajes', 'no me envíen mensajes', 'dejen de enviarme mensajes',
            'arrêt', 'désabonner', 'se désabonner', 'arrêtez les messages', "arrêtez de m'envoyer des messages"];
        return in_array($text, $stops, true);
    }

    private function reply(array $user, int $identityId, string $key): void
    {
        $this->store->queueUserReply($user['id'], Messages::text($user['language'], $key), [], $identityId);
    }
}
