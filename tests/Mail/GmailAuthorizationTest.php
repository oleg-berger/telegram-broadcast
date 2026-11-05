<?php
declare(strict_types=1);

namespace Broadcast\Tests\Mail;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use Broadcast\Infrastructure\Mail\GmailAuthorization;
use Broadcast\Infrastructure\Mail\GmailAuthorizationFailure;
use PHPUnit\Framework\TestCase;

final class GmailAuthorizationTest extends TestCase
{
    public function testDesktopFlowUsesOfflineScopePkceStateAndMatchingRedirect(): void
    {
        $http = $this->createMock(HttpClient::class);
        $auth = new GmailAuthorization('client', 'secret', $http);
        $redirect = 'http://127.0.0.1:12345/oauth2callback';
        parse_str(parse_url($auth->url($redirect), PHP_URL_QUERY), $query);
        self::assertSame(GmailAuthorization::SCOPE, $query['scope']);
        self::assertSame('offline', $query['access_type']);
        self::assertSame('consent', $query['prompt']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertArrayNotHasKey('client_secret', $query);
        $callback = '/oauth2callback?' . http_build_query(['state' => $query['state'], 'code' => 'private-code']);
        self::assertSame('private-code', $auth->callback($callback));
        $http->expects(self::once())->method('post')->willReturnCallback(static function (string $url, array $headers, string $body, int $timeout) use ($query, $redirect): HttpResponse {
            self::assertSame('https://oauth2.googleapis.com/token', $url);
            parse_str($body, $params);
            self::assertSame($redirect, $params['redirect_uri']);
            self::assertSame('private-code', $params['code']);
            self::assertSame('authorization_code', $params['grant_type']);
            self::assertSame($query['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $params['code_verifier'], true)), '+/', '-_'), '='));
            return new HttpResponse(200, json_encode(['refresh_token' => 'private-refresh', 'scope' => GmailAuthorization::SCOPE], JSON_THROW_ON_ERROR));
        });
        self::assertSame('private-refresh', $auth->exchange($auth->callback($callback), $redirect));
    }

    public function testInvalidStateAndDeniedConsentCannotExchangeCodes(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects(self::never())->method('post');
        $auth = new GmailAuthorization('client', 'secret', $http);
        parse_str(parse_url($auth->url('http://127.0.0.1:12345/oauth2callback'), PHP_URL_QUERY), $query);
        foreach (['state' => '/oauth2callback?state=private-wrong&code=private-code', 'denied' => '/oauth2callback?state=' . $query['state'] . '&error=access_denied', 'array' => '/oauth2callback?state[]=private&code=private-code', 'route' => '/other?state=' . $query['state'] . '&code=private-code'] as $target) {
            try { $auth->callback($target); self::fail('Invalid callback accepted.'); } catch (ApiFailure $failure) {
                self::assertStringNotContainsString('private', $failure->getMessage());
            }
        }
    }

    public function testScopeAndRefreshTokenAreRequired(): void
    {
        foreach ([['scope' => GmailAuthorization::SCOPE], ['refresh_token' => 'private', 'scope' => 'openid']] as $json) {
            $http = $this->createMock(HttpClient::class);
            $http->method('post')->willReturn(new HttpResponse(200, json_encode($json, JSON_THROW_ON_ERROR)));
            try { (new GmailAuthorization('client', 'secret', $http))->exchange('code', 'http://127.0.0.1:12345/oauth2callback'); self::fail('Invalid grant accepted.'); } catch (GmailAuthorizationFailure $failure) {
                self::assertStringNotContainsString('private', $failure->getMessage());
                self::assertStringNotContainsString('private', $failure->diagnostic());
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authorizationFailures')]
    public function testSafeDiagnosticsDistinguishFailures(HttpResponse|HttpTransportException $response, string $expected): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects(self::once())->method('post')->willReturnCallback(static function () use ($response): HttpResponse {
            if ($response instanceof HttpTransportException) { throw $response; }
            return $response;
        });
        try {
            (new GmailAuthorization('private-client', 'private-secret', $http))->exchange('private-code', 'http://127.0.0.1:12345/oauth2callback');
            self::fail('Invalid exchange accepted.');
        } catch (GmailAuthorizationFailure $failure) {
            self::assertStringContainsString($expected, $failure->diagnostic());
            self::assertStringNotContainsString('private', $failure->diagnostic() . $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public static function authorizationFailures(): array
    {
        return [
            'timeout' => [new HttpTransportException('private upstream', true, 28), 'Тайм-аут'],
            'legacy timeout' => [new HttpTransportException('private upstream', true), 'Тайм-аут'],
            'dns' => [new HttpTransportException('private upstream', false, 6), 'DNS'],
            'proxy dns' => [new HttpTransportException('private upstream', false, 5), 'DNS'],
            'connection' => [new HttpTransportException('private upstream', false, 7), 'подключиться'],
            'tls' => [new HttpTransportException('private upstream', false, 60), 'TLS'],
            'ca file' => [new HttpTransportException('private upstream', false, 77), 'TLS'],
            'other transport' => [new HttpTransportException('private upstream', false, 55), 'HTTPS'],
            'expired code' => [new HttpResponse(400, '{"error":"invalid_grant","error_description":"private upstream"}'), 'новую ссылку'],
            'client' => [new HttpResponse(401, '{"error":"invalid_client","error_description":"private upstream"}'), 'Desktop JSON'],
            'unknown upstream' => [new HttpResponse(503, '{"error":"private upstream"}'), 'HTTP 503'],
            'malformed response' => [new HttpResponse(200, 'private invalid JSON'), 'некорректный ответ'],
            'missing scope' => [new HttpResponse(200, '{"refresh_token":"private-refresh"}'), 'gmail.send'],
            'missing refresh' => [new HttpResponse(200, json_encode(['scope' => GmailAuthorization::SCOPE], JSON_THROW_ON_ERROR)), 'refresh token'],
        ];
    }

    public function testExistingTokenFileIsPreservedAndDiagnosticContainsNoSecrets(): void
    {
        $directory = sys_get_temp_dir() . '/gmail-authorize-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $client = $directory . '/client.json';
        $token = $directory . '/token.json';
        file_put_contents($client, '{"installed":{"client_id":"private-client","client_secret":"private-secret"}}');
        file_put_contents($token, '{"refresh_token":"private-refresh"}');
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/gmail-authorize', $client, $token], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(1, proc_close($process));
            self::assertStringContainsString('Этап: output.', $output);
            self::assertStringNotContainsString('private', $output);
            self::assertStringNotContainsString('Stack trace', $output);
            self::assertSame('{"refresh_token":"private-refresh"}', file_get_contents($token));
        } finally {
            unlink($client); unlink($token); rmdir($directory);
        }
    }
}
