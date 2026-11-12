<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
use Broadcast\Application\Messages;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class TelegramCompatibilityTest extends TestCase
{
    public function testReferenceMessagesRemainIdenticalInEveryLanguage(): void
    {
        $reference = json_decode(file_get_contents(__DIR__ . '/WhatsApp/Flow/original-ui.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($reference as $key => $values) {
            foreach (array_keys(Messages::LANGUAGES) as $index => $language) {
                self::assertSame($values[$index], Messages::text($language, $key), $key . ':' . $language);
            }
        }
    }

    public function testDirectoryPreviewRetainsOriginalTextAndButtons(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $bot = new Kernel($store, [99], [100]);
        foreach (['News', 'Subject'] as $index => $text) {
            $bot->handle(['update_id' => $index + 1, 'message' => [
                'from' => ['id' => 100], 'chat' => ['id' => 100, 'type' => 'private'], 'text' => $text,
            ]]);
        }
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        $payload = json_decode($db->query("SELECT payload FROM jobs WHERE kind='message' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        self::assertSame("Тема: Subject\n\nNews\n\nРазослать пользователям или отредактировать?", $payload['text']);
        $buttons = $payload['options']['reply_markup']['inline_keyboard'];
        self::assertSame(['📨 Разослать', '✏️ Изменить текст', '✏️ Изменить тему', 'Отменить черновик'],
            array_column(array_map(static fn ($row) => $row[0], array_slice($buttons, 0, 4)), 'text'));
        self::assertSame('draft:send:1:2', $buttons[0][0]['callback_data']);
        self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind='draft_preview'")->fetchColumn());
    }

    public function testDirectoryApprovalWaitsForSnapshotAndUsesOriginalNotice(): void
    {
        $store = new SqliteStore(':memory:', directoryEnabled: true);
        $store->migrate();
        $store->createDraft(99, 'News');
        $store->saveDraft(1, 1, ['subject' => 'Subject', 'status' => 'pending']);
        $broadcast = $store->approveDraft(1, 2, 100);
        $store->queueBroadcastNotice(99, $broadcast, 'broadcast_approved_author');
        $store->queueBroadcastNotice(100, $broadcast, 'broadcast_approved_staff');
        $snapshotJob = $store->nextJob(time());
        self::assertSame('audience_snapshot', $snapshotJob['kind']);
        $store->completeJob($snapshotJob['id']);
        self::assertNull($store->nextJob(time()));
        $snapshot = new \Broadcast\Domain\RecipientSnapshot('test', time(), [
            ['contact_id' => 1, 'telegram_user_id' => null, 'phone' => '+447700900001',
                'channel' => 'whatsapp', 'address' => '447700900001', 'language' => 'RU'],
        ]);
        self::assertTrue($store->publishAudience($broadcast['id'], $snapshot));
        self::assertFalse($store->publishAudience($broadcast['id'], $snapshot));
        $db = (new \ReflectionProperty($store, 'db'))->getValue($store);
        $notices = $db->query("SELECT user_id,payload FROM jobs WHERE kind='message' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $notices);
        self::assertSame('✅ Рассылка #1 одобрена.', json_decode($notices[0]['payload'], true)['text']);
        self::assertSame('✅ Рассылка #1 одобрена. Получателей: 1. Готовим переводы.', json_decode($notices[1]['payload'], true)['text']);
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery' AND channel='whatsapp'")->fetchColumn());
    }
}
