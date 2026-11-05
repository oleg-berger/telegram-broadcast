<?php
declare(strict_types=1);

namespace Broadcast\Tests\Mail;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use Broadcast\Infrastructure\Mail\GmailApiMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GmailApiMailerTest extends TestCase
{
    private function token(string $value = 'private-access', int $expires = 3600): HttpResponse
    {
        return new HttpResponse(200, json_encode(['access_token' => $value, 'token_type' => 'Bearer', 'expires_in' => $expires], JSON_THROW_ON_ERROR));
    }

    private function mailer(QueuedGoogleHttp $http, ?\Closure $clock = null): GmailApiMailer
    {
        return new GmailApiMailer('private-client', 'private-secret', 'private-refresh', 'sender@gmail.com', 'Imén Nails', '', $http, $clock);
    }

    public function testSendsOnePrivateMimeEmailThroughGmailAndCachesToken(): void
    {
        $http = new QueuedGoogleHttp([$this->token(), new HttpResponse(200, '{"id":"message-1"}'), new HttpResponse(200, '{"id":"message-2"}')]);
        $mailer = $this->mailer($http);
        $mailer->send('one@example.com', 'Тема 😀', "Абзац 1\n\nАбзац 2");
        $mailer->send('two@example.com', 'Second', 'Second body');
        self::assertCount(3, $http->requests);
        self::assertSame('https://oauth2.googleapis.com/token', $http->requests[0]['url']);
        parse_str($http->requests[0]['body'], $params);
        self::assertSame(['client_id' => 'private-client', 'client_secret' => 'private-secret', 'refresh_token' => 'private-refresh', 'grant_type' => 'refresh_token'], $params);
        foreach ([1 => 'one@example.com', 2 => 'two@example.com'] as $index => $recipient) {
            $request = $http->requests[$index];
            self::assertSame('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $request['url']);
            self::assertSame('Bearer private-access', $request['headers']['Authorization']);
            self::assertSame(20, $request['timeout']);
            $raw = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR)['raw'];
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $raw);
            $mime = base64_decode(strtr($raw, '-_', '+/'), true);
            self::assertStringContainsString('To: ' . $recipient, $mime);
            self::assertStringNotContainsString($index === 1 ? 'two@example.com' : 'one@example.com', $mime);
            self::assertStringNotContainsString('Bcc:', $mime);
            self::assertStringNotContainsString('Cc:', $mime);
            self::assertStringContainsString('text/plain; charset=utf-8', $mime);
            self::assertStringNotContainsString('private-secret', $mime);
            if ($index === 1) {
                [$headers, $body] = explode("\r\n\r\n", $mime, 2);
                self::assertStringContainsString(base64_encode('Тема 😀'), $headers);
                self::assertSame("Абзац 1\n\nАбзац 2", rtrim(base64_decode($body), "\r\n"));
            }
        }
    }

    public function testExpiredTokenIsRenewedWithControlledTime(): void
    {
        $now = 1000;
        $http = new QueuedGoogleHttp([$this->token('old', 60), new HttpResponse(200, '{"id":"one"}'), $this->token('new'), new HttpResponse(200, '{"id":"two"}')]);
        $mailer = $this->mailer($http, static function () use (&$now): int { return $now; });
        $mailer->send('one@example.com', 'Subject', 'Body');
        $now += 31;
        $mailer->send('two@example.com', 'Subject', 'Body');
        self::assertCount(4, $http->requests);
        self::assertSame('Bearer new', $http->requests[3]['headers']['Authorization']);
    }

    public static function failures(): iterable
    {
        yield 'invalid grant' => [false, new HttpResponse(400, '{"error":"invalid_grant","error_description":"private-secret"}'), false, null];
        yield 'oauth server' => [false, new HttpResponse(503, 'private-secret'), true, null];
        yield 'permission' => [true, new HttpResponse(403, '{"error":{"message":"private-secret"}}'), false, null];
        yield 'rate' => [true, new HttpResponse(403, '{"error":{"errors":[{"reason":"rateLimitExceeded"}],"message":"private-secret"}}'), true, null];
        yield 'daily' => [true, new HttpResponse(403, '{"error":{"errors":[{"reason":"dailyLimitExceeded"}]}}'), true, 86400];
        yield 'retry after' => [true, new HttpResponse(429, 'private-secret', ['Retry-After' => '120']), true, 120];
        yield 'bad retry header' => [true, new HttpResponse(429, 'private-secret', ['Retry-After' => 'private-secret']), true, null];
        yield 'network' => [false, new HttpTransportException('private-secret'), true, null];
        yield 'send network' => [true, new HttpTransportException('private-secret'), true, null];
        yield 'missing token' => [false, new HttpResponse(200, '{"private-secret":true}'), true, null];
        yield 'missing id' => [true, new HttpResponse(200, '{"private-secret":true}'), true, null];
        yield 'unauthorized' => [true, new HttpResponse(401, 'private-secret'), true, null];
    }

    #[DataProvider('failures')]
    public function testFailuresAreClassifiedWithoutLeakingSecrets(bool $delivery, HttpResponse|HttpTransportException $response, bool $transient, ?int $retry): void
    {
        $http = new QueuedGoogleHttp($delivery ? [$this->token(), $response] : [$response]);
        try {
            $this->mailer($http)->send('one@example.com', 'Private subject', 'Private body');
            self::fail('Failure was accepted.');
        } catch (ApiFailure $failure) {
            self::assertSame($transient, $failure->transient);
            self::assertSame($retry, $failure->retryAfter);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('private', $failure->getMessage());
            self::assertStringNotContainsString('one@example.com', $failure->getMessage());
        }
    }

    public function testUnauthorizedSendDoesNotResendAndRefreshesOnNextAttempt(): void
    {
        $http = new QueuedGoogleHttp([$this->token('old'), new HttpResponse(401, '{}'), $this->token('new'), new HttpResponse(200, '{"id":"one"}')]);
        $mailer = $this->mailer($http);
        try { $mailer->send('one@example.com', 'Subject', 'Body'); } catch (ApiFailure) {}
        self::assertCount(2, $http->requests);
        $mailer->send('one@example.com', 'Subject', 'Body');
        self::assertCount(4, $http->requests);
        self::assertSame('Bearer new', $http->requests[3]['headers']['Authorization']);
    }

    public function testHttpDateRetryAfterIsHonored(): void
    {
        $http = new QueuedGoogleHttp([$this->token(), new HttpResponse(429, '{}', ['Retry-After' => 'Thu, 01 Jan 1970 00:18:40 GMT'])]);
        try { $this->mailer($http, static fn (): int => 1000)->send('one@example.com', 'Subject', 'Body'); self::fail('Rate limit accepted.'); } catch (ApiFailure $failure) {
            self::assertSame(120, $failure->retryAfter);
            self::assertTrue($failure->transient);
        }
    }

    public function testAssignedPhpmailerPropertiesExist(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Mail/GmailApiMailer.php');
        preg_match_all('/\$mail->([A-Za-z]+)\s*=/', $source, $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $property) {
            self::assertTrue(property_exists(\PHPMailer\PHPMailer\PHPMailer::class, $property));
        }
    }
}

final class QueuedGoogleHttp implements HttpClient
{
    public array $requests = [];
    public function __construct(private array $responses) {}
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        $response = array_shift($this->responses);
        if ($response instanceof HttpTransportException) { throw $response; }
        if (!$response instanceof HttpResponse) { throw new \RuntimeException('Unexpected HTTP request in test.'); }
        return $response;
    }
}
