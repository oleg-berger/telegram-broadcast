<?php
declare(strict_types=1);

namespace Broadcast\Application;

final class ApplicationCard
{
    public static function send(Store $store, int $staff, array $user): void
    {
        $id = $user['id'];
        $revision = $user['revision'];
        $rows = [];
        if ($user['status'] === 'pending') {
            foreach (['approve' => '✅ Одобрить', 'changes' => '✏️ Вернуть на исправление', 'reject' => '❌ Отклонить'] as $action => $label) {
                $rows[] = [['text' => $label, 'callback_data' => "app:$action:$id:$revision"]];
            }
        } elseif ($user['status'] === 'rejected') {
            $rows[] = [['text' => 'Разрешить повторную подачу', 'callback_data' => "app:reopen:$id:$revision"]];
        }
        $store->queueReply(
            $staff,
            "Заявка #$id · " . $user['status'] . "\n" . self::channels($store, $user, 'RU')
                . "\n" . Messages::text('RU', 'review', [...$user, 'language' => Messages::LANGUAGES[$user['language']]]),
            ['reply_markup' => ['inline_keyboard' => $rows]],
        );
    }

    public static function channels(Store $store, array $user, string $language): string
    {
        $channels = [];
        foreach ($store->identities($user['id']) as $identity) {
            $label = $identity['channel'] === 'telegram' ? 'Telegram' : 'WhatsApp';
            $channels[] = $identity['enabled'] ? $label : Messages::text($language, 'channel_disabled', ['channel' => $label]);
        }
        if ($user['email'] !== '') { $channels[] = 'email'; }
        return Messages::text($language, 'application_channels', ['channels' => implode(', ', $channels)]);
    }

    public static function sendChannelStatus(Store $store, array $user, ?int $identityId = null): void
    {
        foreach ($store->identities($user['id']) as $identity) {
            if ($identity['channel'] === 'whatsapp') {
                $store->queueUserReply($user['id'], self::channels($store, $user, $user['language']), [], $identityId);
                return;
            }
        }
    }
}
