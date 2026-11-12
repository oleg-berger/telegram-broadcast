<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Integration;

use Broadcast\Infrastructure\Http\{HttpClient, HttpResponse};
use Broadcast\Local\TelegramTime;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/whatsapp/TelegramTime.php';

final class TelegramTimeTest extends TestCase
{
    public function testUsesAuthenticatedServerTimeInsteadOfLocalClock(): void
    {
        $http = new class implements HttpClient {
            public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
            {
                TestCase::assertSame('https://api.telegram.org/bot123:test-secret/getMe', $url);
                return new HttpResponse(200, '{"ok":true}', ['Date' => 'Tue, 03 Nov 2026 08:09:19 GMT']);
            }
        };
        self::assertSame(1793693359, TelegramTime::cutoff($http, '123:test-secret'));
    }

    public function testRejectsMissingOrMalformedTimeAndApiFailureWithoutLeakingResponse(): void
    {
        foreach ([new HttpResponse(200, '{"ok":true}'), new HttpResponse(200, '{"ok":true}', ['Date' => 'secret-invalid']),
            new HttpResponse(401, '{"ok":false,"description":"secret-upstream"}', ['Date' => 'Tue, 03 Nov 2026 08:09:19 GMT'])] as $response) {
            $http = new class($response) implements HttpClient {
                public function __construct(private HttpResponse $response) {}
                public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse { return $this->response; }
            };
            try {
                TelegramTime::cutoff($http, '123:secret-token');
                self::fail('An untrusted cutoff must not be accepted.');
            } catch (\RuntimeException $failure) {
                self::assertSame('Telegram time check failed.', $failure->getMessage());
                self::assertNull($failure->getPrevious());
            }
        }
    }
}
