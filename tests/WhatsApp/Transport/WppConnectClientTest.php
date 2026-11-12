<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Transport;

use Broadcast\Domain\WhatsAppFailure;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\WhatsApp\WhatsAppHttpClient;
use Broadcast\Infrastructure\WhatsApp\WhatsAppHttpFailure;
use Broadcast\Infrastructure\WhatsApp\WppConnectClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WppConnectClientTest extends TestCase
{
    private const string PHONE = '77010000001';

    public function testSendsOneAuthenticatedTextRequestAndReturnsAcceptedId(): void
    {
        $http = new TransportHttpFake([new HttpResponse(201, '{"status":"success","response":[{"id":"ABC123","to":"77010000001@s.whatsapp.net","type":"chat"}],"mapper":"return"}')]);
        $client = $this->client($http);
        self::assertSame('ABC123', $client->sendText(self::PHONE, "Привет, café 👩🏽‍💻\nLine two"));
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://127.0.0.1:21465/api/whatsapp-test/send-message', $request['url']);
        self::assertSame('Bearer fixture-token', $request['headers']['Authorization']);
        self::assertSame(['phone' => self::PHONE, 'message' => "Привет, café 👩🏽‍💻\nLine two", 'isGroup' => false], json_decode($request['body'], true));
        self::assertGreaterThanOrEqual(60, $request['timeoutSeconds']);
    }

    #[DataProvider('forbiddenRecipients')]
    public function testUnapprovedOrUnresolvedRecipientNeverReachesHttp(string $address, array $allowlist): void
    {
        $http = new TransportHttpFake([]);
        $client = new WppConnectClient('http://127.0.0.1:21465', 'whatsapp-test', 'fixture-token', $allowlist, $http);
        try {
            $client->sendText($address, 'Text');
            self::fail('Recipient must fail closed.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('permanent', $failure->category);
            self::assertSame([], $http->requests);
            self::assertStringNotContainsString($address, $failure->getMessage());
        }
    }

    public static function forbiddenRecipients(): array
    {
        return [[self::PHONE, []], ['77010000002', [self::PHONE]], ['123456789012345@lid', [self::PHONE]], ['77010000001@g.us', [self::PHONE]]];
    }

    #[DataProvider('unsafeConfig')]
    public function testUnsafeConfigurationIsRejectedWithoutDisclosingValues(string $url, string $session, string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid WhatsApp transport configuration.');
        new WppConnectClient($url, $session, $token, [self::PHONE], new TransportHttpFake([]));
    }

    public static function unsafeConfig(): array
    {
        return [
            ['http://example.com', 'whatsapp-test', 'fixture-token'],
            ['http://127.0.0.1:21465/?token=fixture-token', 'whatsapp-test', 'fixture-token'],
            ['http://fixture-token@127.0.0.1:21465', 'whatsapp-test', 'fixture-token'],
            ['http://127.0.0.1:21465', '../session', 'fixture-token'],
            ['http://127.0.0.1:21465', 'whatsapp-test', "secret\r\nInjected: yes"],
            ['http://127.0.0.1:21465', 'whatsapp-test', ''],
            ['http://127.0.0.1:0', 'whatsapp-test', 'fixture-token'],
            ["https://example.com\n", 'whatsapp-test', 'fixture-token'],
        ];
    }

    public function testUnicodeWhitespaceIsRejectedBeforePostingAsThePinnedServerRequires(): void
    {
        $http = new TransportHttpFake([]);
        try {
            $this->client($http)->sendText(self::PHONE, "\u{00A0}\u{2003}\n");
            self::fail('Blank Unicode text is not a valid WhatsApp message.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('permanent', $failure->category);
            self::assertSame([], $http->requests);
        }
    }

    public function testProvenConnectionFailureBeforePostIsUnavailableAndSafeToRetry(): void
    {
        $http = new TransportHttpFake([new WhatsAppHttpFailure(false)]);
        try {
            $this->client($http)->sendText(self::PHONE, 'Text');
            self::fail('Expected unavailable transport.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('unavailable', $failure->category);
            self::assertCount(1, $http->requests);
        }
    }

    public function testWrongAcceptedRecipientDoesNotConfirmTheSend(): void
    {
        $http = new TransportHttpFake([new HttpResponse(201, '{"status":"success","response":[{"id":"ABC123","to":"77010000002@s.whatsapp.net","type":"chat"}],"mapper":"return"}')]);
        try {
            $this->client($http)->sendText(self::PHONE, 'Text');
            self::fail('Receipt must match the requested recipient.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('uncertain', $failure->category);
        }
    }

    public function testOversizedRequestCannotReachHttp(): void
    {
        $http = new TransportHttpFake([]);
        try {
            $this->client($http)->sendText(self::PHONE, str_repeat("\x01", 30000));
            self::fail('Escaped request must fit the server body cap.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('permanent', $failure->category);
            self::assertSame([], $http->requests);
        }
    }

    #[DataProvider('sendFailures')]
    public function testClassifiesSendFailuresWithoutRetryOrUpstreamLeak(int $status, string $body, string $category, ?int $retryAfter): void
    {
        $http = new TransportHttpFake([new HttpResponse($status, $body, ['Retry-After' => '17'])]);
        try {
            $this->client($http)->sendText(self::PHONE, 'Private text');
            self::fail('Expected transport failure.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame($category, $failure->category);
            self::assertSame($retryAfter, $failure->retryAfter);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('sensitive', $failure->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public static function sendFailures(): array
    {
        return [
            [502, '{"message":"sensitive"}', 'uncertain', null],
            [504, '{"message":"sensitive"}', 'uncertain', null],
            [500, '{"status":"error","message":"sensitive"}', 'uncertain', null],
            [503, '{"status":"error"}', 'uncertain', null],
            [408, '{"status":"error"}', 'uncertain', null],
            [302, '{"status":"error"}', 'uncertain', null],
            [201, '{"status":"success","response":[]}', 'uncertain', null],
            [201, 'sensitive invalid json', 'uncertain', null],
            [404, '{"status":"disconnected","session":"whatsapp-test"}', 'unavailable', null],
            [401, '{"message":"sensitive token"}', 'unavailable', null],
            [429, '{"status":"error","message":"sensitive"}', 'transient', 17],
            [400, '{"status":"error","message":"sensitive"}', 'permanent', null],
        ];
    }

    public function testLostPostResponseIsUncertainAndExceptionChainIsSanitized(): void
    {
        $http = new TransportHttpFake([new \RuntimeException('private recipient and token')]);
        try {
            $this->client($http)->sendText(self::PHONE, 'Private text');
            self::fail('Expected uncertainty.');
        } catch (WhatsAppFailure $failure) {
            self::assertSame('uncertain', $failure->category);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('private', $failure->getMessage());
            self::assertCount(1, $http->requests);
        }
    }

    public function testHealthRequiresConnectedStatusForCorrectSession(): void
    {
        $http = new TransportHttpFake([
            new HttpResponse(200, '{"status":"CONNECTED","session":"whatsapp-test","urlcode":"private QR"}'),
            new HttpResponse(200, '{"status":"CLOSED","session":"whatsapp-test"}'),
            new HttpResponse(200, '{"status":"CONNECTED","session":"other"}'),
        ]);
        $client = $this->client($http);
        self::assertTrue($client->connected());
        self::assertFalse($client->connected());
        self::assertFalse($client->connected());
        self::assertSame('GET', $http->requests[0]['method']);
        self::assertStringEndsWith('/status-session', $http->requests[0]['url']);
    }

    public function testMapsLidOnlyThroughMatchingAuthenticatedPair(): void
    {
        $http = new TransportHttpFake([
            new HttpResponse(200, '{"phoneNumber":{"id":"77010000001","user":"77010000001","server":"s.whatsapp.net"},"lid":{"id":"123456789012345","user":"123456789012345","server":"lid"}}'),
            new HttpResponse(200, '{"lid":{"id":"123456789012345","user":"123456789012345","server":"lid"}}'),
            new HttpResponse(200, '{"phoneNumber":{"id":"77010000001","user":"77010000001","server":"s.whatsapp.net"},"lid":{"id":"999999999999999","user":"999999999999999","server":"lid"}}'),
        ]);
        $client = $this->client($http);
        self::assertSame('+77010000001', $client->resolvePhone('123456789012345@lid'));
        self::assertNull($client->resolvePhone('123456789012345@lid'));
        self::assertNull($client->resolvePhone('123456789012345@lid'));
        self::assertStringEndsWith('/contact/pn-lid/123456789012345%40lid', $http->requests[0]['url']);
        self::assertSame('Bearer fixture-token', $http->requests[0]['headers']['Authorization']);
    }

    public function testNativePhoneAddressIsVerifiedWithoutLidInference(): void
    {
        $http = new TransportHttpFake([]);
        $client = $this->client($http);
        self::assertSame('+77010000001', $client->resolvePhone('77010000001@s.whatsapp.net'));
        self::assertSame('+77010000001', $client->resolvePhone('77010000001@c.us'));
        self::assertNull($client->resolvePhone('123456789012345@g.us'));
        self::assertSame([], $http->requests);
    }

    public function testEventsUseCallerCursorAndRejectCrossSession(): void
    {
        $events = [
            ['sequence' => 6, 'id' => 'event-a', 'session' => 'other', 'event' => 'onmessage', 'createdAt' => 1700000000000, 'data' => []],
            ['sequence' => 7, 'id' => 'event-b', 'session' => 'whatsapp-test', 'event' => 'onmessage', 'createdAt' => 1700000001000, 'data' => ['body' => 'Private']],
        ];
        $http = new TransportHttpFake([new HttpResponse(200, json_encode(['status' => 'success', 'response' => $events, 'nextCursor' => 999999], JSON_THROW_ON_ERROR))]);
        self::assertSame([$events[1]], $this->client($http)->events(5, 20));
        self::assertStringEndsWith('/events?cursor=5&limit=20', $http->requests[0]['url']);
    }

    private function client(TransportHttpFake $http): WppConnectClient
    {
        return new WppConnectClient('http://127.0.0.1:21465', 'whatsapp-test', 'fixture-token', [self::PHONE], $http);
    }
}

final class TransportHttpFake implements WhatsAppHttpClient
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeoutSeconds');
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (!$response instanceof HttpResponse) {
            throw new \LogicException('Unexpected HTTP request.');
        }
        return $response;
    }
}
