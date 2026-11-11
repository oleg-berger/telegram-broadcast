<?php
declare(strict_types=1);

namespace Broadcast\Tests\Mail;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use Broadcast\Infrastructure\Mail\GmailApiMailer;
use Broadcast\Infrastructure\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

final class MailUncertaintyTest extends TestCase
{
    private function token(): HttpResponse
    {
        return new HttpResponse(200, '{"access_token":"test","token_type":"Bearer","expires_in":3600}');
    }

    private function gmail(UncertaintyHttp $http): GmailApiMailer
    {
        return new GmailApiMailer('client', 'secret', 'refresh', 'sender@example.test', 'Sender', '', $http);
    }

    public function testGmailLostSendResponseAndInvalidAcceptanceAreUncertain(): void
    {
        foreach ([new HttpTransportException('private upstream detail', timeout: true), new HttpResponse(200, '{}')] as $response) {
            $http = new UncertaintyHttp([$this->token(), $response]);
            try { $this->gmail($http)->send('recipient@example.test', 'Subject', 'Body'); self::fail('Send failure accepted.'); }
            catch (ApiFailure $failure) {
                self::assertTrue($failure->uncertain);
                self::assertNull($failure->getPrevious());
                self::assertStringNotContainsString('private', $failure->getMessage());
                self::assertSame(2, $http->calls);
            }
        }
    }

    public function testAuthorizationFailureAndConnectionRefusalAreSafeToRetry(): void
    {
        foreach ([
            [new HttpTransportException('private authorization failure')],
            [$this->token(), new HttpTransportException('private connect failure', transportCode: CURLE_COULDNT_CONNECT)],
        ] as $responses) {
            try { $this->gmail(new UncertaintyHttp($responses))->send('recipient@example.test', 'Subject', 'Body'); self::fail('Send failure accepted.'); }
            catch (ApiFailure $failure) { self::assertFalse($failure->uncertain); self::assertTrue($failure->transient); }
        }
    }

    public function testPrepareRefreshesOnlyOnceThenDeliveryNeedsOneExternalOperation(): void
    {
        $http = new UncertaintyHttp([$this->token(), new HttpResponse(200, '{"id":"accepted"}')]);
        $mail = $this->gmail($http);
        self::assertTrue($mail->prepareDelivery());
        self::assertSame(1, $http->calls);
        self::assertFalse($mail->prepareDelivery());
        self::assertSame(1, $http->calls);
        $mail->send('recipient@example.test', 'Subject', 'Body');
        self::assertSame(2, $http->calls);
    }

    public function testSmtpLostAcceptanceIsUncertainAfterCompleteMessageReceived(): void
    {
        [$failure, $received] = $this->smtp('lost_ack');
        self::assertInstanceOf(ApiFailure::class, $failure);
        self::assertTrue($received);
        self::assertTrue($failure->uncertain);
    }

    public function testSmtpExplicitFinalRejectionIsNotUncertain(): void
    {
        foreach (['reject_451' => true, 'reject_550' => false] as $mode => $retryable) {
            [$failure, $received] = $this->smtp($mode);
            self::assertInstanceOf(ApiFailure::class, $failure);
            self::assertTrue($received);
            self::assertFalse($failure->uncertain);
            self::assertSame($retryable, $failure->transient);
        }
    }

    public function testSmtpDisconnectBeforeDataCannotHaveAcceptedMail(): void
    {
        [$failure, $received] = $this->smtp('before_data');
        self::assertInstanceOf(ApiFailure::class, $failure);
        self::assertFalse($received);
        self::assertFalse($failure->uncertain);
        self::assertTrue($failure->transient);
    }

    public function testSmtpPositiveAcceptanceRemainsSuccessful(): void
    {
        [$failure, $received] = $this->smtp('accepted');
        self::assertNull($failure);
        self::assertTrue($received);
    }

    /** @return array{?ApiFailure,bool} */
    private function smtp(string $mode): array
    {
        $code = <<<'PHP'
        $mode = $argv[1];
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($server, false), "\n"; flush();
        $client = stream_socket_accept($server, 10); if (!$client) { exit(1); }
        stream_set_timeout($client, 10); fwrite($client, "220 localhost ESMTP\r\n");
        $data = false; $received = false;
        while (($line = fgets($client)) !== false) {
            if ($data) {
                if ($line !== ".\r\n") { continue; }
                $received = true; $data = false;
                if ($mode === 'lost_ack') { break; }
                fwrite($client, match($mode) { 'reject_451'=>"451 try later\r\n", 'reject_550'=>"550 rejected\r\n", default=>"250 accepted\r\n" });
            } elseif (str_starts_with($line, 'DATA')) {
                if ($mode === 'before_data') { break; }
                $data = true; fwrite($client, "354 continue\r\n");
            } elseif (str_starts_with($line, 'QUIT')) { fwrite($client, "221 goodbye\r\n"); break; }
            else { fwrite($client, "250 OK\r\n"); }
        }
        fclose($client); fclose($server); echo $received ? 'RECEIVED' : 'NOT_RECEIVED';
        PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process); fclose($pipes[0]);
        try {
            $address = trim(fgets($pipes[1]));
            self::assertMatchesRegularExpression('/\A127\.0\.0\.1:[0-9]+\z/', $address);
            $mail = new SmtpMailer('127.0.0.1', (int) substr($address, strrpos($address, ':') + 1), 'none', '', '', 'sender@example.test', 'Sender', '', true);
            $failure = null;
            try { $mail->send('recipient@example.test', 'Subject', 'Body'); }
            catch (ApiFailure $error) { $failure = $error; }
            $received = stream_get_contents($pipes[1]) === 'RECEIVED';
            self::assertSame('', stream_get_contents($pipes[2]));
            return [$failure, $received];
        } finally {
            fclose($pipes[1]); fclose($pipes[2]); proc_terminate($process); proc_close($process);
        }
    }
}

final class UncertaintyHttp implements HttpClient
{
    public int $calls = 0;
    public function __construct(private array $responses) {}
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        ++$this->calls;
        $response = array_shift($this->responses);
        if ($response instanceof HttpTransportException) { throw $response; }
        if (!$response instanceof HttpResponse) { throw new \LogicException('Unexpected test request.'); }
        return $response;
    }
}
