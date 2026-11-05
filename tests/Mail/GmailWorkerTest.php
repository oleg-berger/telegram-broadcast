<?php
declare(strict_types=1);

namespace Broadcast\Tests\Mail;

use Broadcast\Application\Kernel;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Mail\GmailApiMailer;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class GmailWorkerTest extends TestCase
{
    public function testFailedGmailCanRetryWithoutRepeatingTelegramOrLeakingSecrets(): void
    {
        $store = new SqliteStore(':memory:');
        $store->migrate();
        $store->saveUser(['id' => 1, 'language' => 'FR', 'step' => 'done', 'name' => 'Private name', 'company' => 'Private company',
            'phone' => '', 'country' => 'France', 'email' => 'private@example.com', 'status' => 'approved', 'subscribed' => 1, 'completed' => 1, 'choosing_language' => 0]);
        $bot = new Kernel($store, [99], [100]);
        foreach ([1 => 'Private text', 2 => 'Private subject'] as $id => $text) {
            $bot->handle(['update_id' => $id, 'message' => ['from' => ['id' => 100], 'chat' => ['id' => 100, 'type' => 'private'], 'text' => $text]]);
        }
        $bot->handle(['update_id' => 3, 'callback_query' => ['id' => '3', 'from' => ['id' => 100], 'message' => ['chat' => ['id' => 100, 'type' => 'private']], 'data' => 'draft:send:1:2']]);
        $messages = $logs = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text) use (&$messages): void { $messages[] = [$id, $text]; });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(static fn (string $text, string $language): string => $language . ':' . $text);
        $responses = [new HttpResponse(400, '{"error":"invalid_grant","error_description":"Private token"}'),
            new HttpResponse(200, '{"access_token":"private-access","token_type":"Bearer","expires_in":3600}'), new HttpResponse(200, '{"id":"sent"}')];
        $http = $this->createMock(HttpClient::class);
        $http->expects(self::exactly(3))->method('post')->willReturnCallback(static function () use (&$responses): HttpResponse { return array_shift($responses); });
        $mail = new GmailApiMailer('private-client', 'private-secret', 'private-refresh', 'sender@gmail.com', 'Bot', http: $http);
        $worker = new Worker($store, $telegram, $translator, static function (array $record) use (&$logs): void { $logs[] = $record; }, $mail);
        $now = time();
        for ($i = 0; $i < 100 && $worker->tick($now + $i * 10); ++$i) {}
        self::assertLessThan(100, $i);
        self::assertSame([[1, 'FR:Private text']], array_values(array_filter($messages, fn ($m) => $m[0] === 1)));
        $reports = array_values(array_filter($messages, fn ($m) => str_contains($m[1], 'Telegram:')));
        self::assertCount(1, $reports);
        self::assertSame(100, $reports[0][0]);
        self::assertStringContainsString('Email (принято почтовым сервисом): отправлено 0, пропущено 0, ошибки 1', $reports[0][1]);
        self::assertTrue($store->retryBroadcast(1));
        for ($i = 0; $i < 100 && $worker->tick($now + 1000 + $i * 10); ++$i) {}
        self::assertLessThan(100, $i);
        self::assertSame([[1, 'FR:Private text']], array_values(array_filter($messages, fn ($m) => $m[0] === 1)));
        $reports = array_values(array_filter($messages, fn ($m) => str_contains($m[1], 'Telegram:')));
        self::assertCount(2, $reports);
        self::assertStringContainsString('Email (принято почтовым сервисом): отправлено 1, пропущено 0, ошибки 0', $reports[1][1]);
        self::assertStringNotContainsString('Private', json_encode($logs, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private', json_encode($logs, JSON_THROW_ON_ERROR));
    }
}
