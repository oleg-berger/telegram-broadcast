<?php

declare(strict_types=1);

namespace Broadcast\Tests\Http;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use Broadcast\Infrastructure\Http\TelegramClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramClientTest extends TestCase
{
    public function testSendMessageUsesTelegramJsonApiWithoutParseMode(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"ok":true,"result":{"message_id":1}}'));
        $client = new TelegramClient('bot-secret-token', $http);

        $client->sendMessage(42, '<b>literal text</b>', [
            'disable_notification' => true,
            'parse_mode' => 'HTML',
            'chat_id' => 99,
        ]);

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('https://api.telegram.org/botbot-secret-token/sendMessage', $request['url']);
        self::assertSame(['Content-Type' => 'application/json'], $request['headers']);
        self::assertSame(30, $request['timeout']);
        self::assertSame(
            ['disable_notification' => true, 'chat_id' => 42, 'text' => '<b>literal text</b>'],
            json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testGetUpdatesUsesLongPollPayloadAndReturnsUpdates(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"ok":true,"result":[{"update_id":101}]}'));
        $client = new TelegramClient('token', $http);

        $updates = $client->getUpdates(100, 12);

        self::assertSame([['update_id' => 101]], $updates);
        self::assertSame(17, $http->requests[0]['timeout']);
        self::assertSame(
            ['offset' => 100, 'timeout' => 12, 'allowed_updates' => ['message', 'callback_query']],
            json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testAnswerCallbackQuerySendsCallbackIdentifier(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"ok":true,"result":true}'));
        $client = new TelegramClient('token', $http);

        $client->answerCallbackQuery('callback-7');

        self::assertSame('https://api.telegram.org/bottoken/answerCallbackQuery', $http->requests[0]['url']);
        self::assertSame(
            ['callback_query_id' => 'callback-7'],
            json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testSendDocumentUploadsMultipartBody(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"ok":true,"result":{"message_id":1}}'));
        $client = new TelegramClient('token', $http);
        $path = tempnam(sys_get_temp_dir(), 'document-test-');
        file_put_contents($path, 'xlsx-bytes');

        try {
            $client->sendDocument(42, $path, 'users.xlsx');
        } finally {
            unlink($path);
        }

        $request = $http->requests[0];
        self::assertSame('https://api.telegram.org/bottoken/sendDocument', $request['url']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $request['headers']['Content-Type']);
        self::assertStringContainsString("name=\"chat_id\"\r\n\r\n42\r\n", $request['body']);
        self::assertStringContainsString('filename="users.xlsx"', $request['body']);
        self::assertStringContainsString('xlsx-bytes', $request['body']);
    }

    public function testSendPhotoUploadsCaptionAndImage(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"ok":true,"result":{"message_id":2}}'));
        $client = new TelegramClient('token', $http);
        $path = tempnam(sys_get_temp_dir(), 'photo-test-');
        file_put_contents($path, 'png-bytes');

        try {
            $client->sendPhoto(42, $path, 'Imén Nails help');
        } finally {
            unlink($path);
        }

        $request = $http->requests[0];
        self::assertSame('https://api.telegram.org/bottoken/sendPhoto', $request['url']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $request['headers']['Content-Type']);
        self::assertStringContainsString("name=\"chat_id\"\r\n\r\n42\r\n", $request['body']);
        self::assertStringContainsString("name=\"caption\"\r\n\r\nImén Nails help\r\n", $request['body']);
        self::assertStringContainsString('filename="help-card.png"', $request['body']);
        self::assertStringContainsString('png-bytes', $request['body']);
    }

    /** @return iterable<string, array{int, string, bool, ?int, bool}> */
    public static function apiFailures(): iterable
    {
        yield 'rate limit with retry delay' => [
            429,
            '{"ok":false,"error_code":429,"description":"secret diagnostic","parameters":{"retry_after":7}}',
            true,
            7,
            false,
        ];
        yield 'server failure' => [503, '{"ok":false,"error_code":503,"description":"down"}', true, null, false];
        yield 'bot blocked' => [403, '{"ok":false,"error_code":403,"description":"bot was blocked"}', false, null, true];
        yield 'bad request' => [400, '{"ok":false,"error_code":400,"description":"bad text: private-message"}', false, null, false];
    }

    #[DataProvider('apiFailures')]
    public function testApiFailuresAreClassifiedWithoutLeakingRemoteDetails(
        int $status,
        string $body,
        bool $transient,
        ?int $retryAfter,
        bool $blocked,
    ): void {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse($status, $body));
        $client = new TelegramClient('token-that-must-stay-secret', $http);

        try {
            $client->sendMessage(42, 'private-message');
            self::fail('Expected an API failure.');
        } catch (ApiFailure $failure) {
            self::assertSame($transient, $failure->transient);
            self::assertSame($retryAfter, $failure->retryAfter);
            self::assertSame($blocked, $failure->blocked);
            self::assertStringNotContainsString('private-message', $failure->getMessage());
            self::assertStringNotContainsString('token-that-must-stay-secret', $failure->getMessage());
            self::assertStringNotContainsString('secret diagnostic', $failure->getMessage());
        }
    }

    public function testTransportTimeoutIsReportedAsSafeTransientFailure(): void
    {
        $http = new FakeHttpClient();
        $http->fail(new HttpTransportException('low-level error containing private-message', true));
        $client = new TelegramClient('token-that-must-stay-secret', $http);

        try {
            $client->sendMessage(42, 'private-message');
            self::fail('Expected an API failure.');
        } catch (ApiFailure $failure) {
            self::assertTrue($failure->transient);
            self::assertStringNotContainsString('private-message', $failure->getMessage());
            self::assertStringNotContainsString('token-that-must-stay-secret', $failure->getMessage());
        }
    }

    public function testMalformedSuccessResponseIsTransientFailure(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{not-json'));
        $client = new TelegramClient('token', $http);

        $this->expectExceptionObject(new ApiFailure('Telegram returned an invalid response.', true));
        $client->getUpdates(0);
    }

    /** @return iterable<string, array{int, bool, bool}> */
    public static function malformedHttpFailures(): iterable
    {
        yield 'forbidden' => [403, false, true];
        yield 'rate limit' => [429, true, false];
        yield 'server failure' => [502, true, false];
    }

    #[DataProvider('malformedHttpFailures')]
    public function testHttpStatusIsClassifiedWhenErrorBodyIsMalformed(
        int $status,
        bool $transient,
        bool $blocked,
    ): void {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse($status, '<invalid response>'));
        $client = new TelegramClient('token', $http);

        try {
            $client->sendMessage(42, 'text');
            self::fail('Expected an API failure.');
        } catch (ApiFailure $failure) {
            self::assertSame($transient, $failure->transient);
            self::assertSame($blocked, $failure->blocked);
        }
    }
}
