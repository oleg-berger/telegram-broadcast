<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Mail;

use Broadcast\Application\MailGateway;
use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\CurlHttpClient;
use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class GmailApiMailer implements MailGateway
{
    private HttpClient $http;
    private \Closure $clock;
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $refreshToken,
        private string $fromAddress,
        private string $fromName,
        private string $replyTo = '',
        ?HttpClient $http = null,
        ?\Closure $clock = null,
    ) {
        $this->http = $http ?? new CurlHttpClient();
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function send(string $to, string $subject, string $text): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addReplyTo($this->replyTo ?: $this->fromAddress);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $text;
            $mail->isHTML(false);
            $mail->preSend();
            $raw = rtrim(strtr(base64_encode($mail->getSentMIMEMessage()), '+/', '-_'), '=');
        } catch (Exception) {
            throw new ApiFailure('Email preparation failed.');
        }
        $token = $this->token();
        $response = $this->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
            'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token,
        ], json_encode(['raw' => $raw], JSON_THROW_ON_ERROR));
        if ($response->status === 401) {
            // Refresh on the next queued attempt; never resend a message inside one attempt.
            $this->accessToken = null;
        }
        $this->check($response, true);
        $json = json_decode($response->body, true);
        if (!is_array($json) || !is_string($json['id'] ?? null) || $json['id'] === '') {
            throw new ApiFailure('Invalid Gmail delivery response.', transient: true);
        }
    }

    private function token(): string
    {
        $now = ($this->clock)();
        if ($this->accessToken !== null && $now < $this->expiresAt) {
            return $this->accessToken;
        }
        $response = $this->post('https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken, 'grant_type' => 'refresh_token',
        ], encoding_type: PHP_QUERY_RFC3986));
        $this->check($response, false);
        $json = json_decode($response->body, true);
        if (!is_array($json) || !is_string($json['access_token'] ?? null) || $json['access_token'] === '' || preg_match('/[\r\n]/', $json['access_token'])
            || ($json['token_type'] ?? null) !== 'Bearer' || !is_int($json['expires_in'] ?? null) || $json['expires_in'] < 1) {
            throw new ApiFailure('Invalid Gmail authorization response.', transient: true);
        }
        $this->accessToken = $json['access_token'];
        $this->expiresAt = $now + max(0, min($json['expires_in'], 86400) - 30);
        return $this->accessToken;
    }

    private function post(string $url, array $headers, string $body): HttpResponse
    {
        try {
            return $this->http->post($url, $headers, $body, 20);
        } catch (HttpTransportException) {
            throw new ApiFailure('Gmail request failed.', transient: true);
        }
    }

    private function check(HttpResponse $response, bool $delivery): void
    {
        if ($response->status >= 200 && $response->status < 300) {
            return;
        }
        $json = json_decode($response->body, true);
        $reasons = [];
        foreach (is_array($json['error']['errors'] ?? null) ? $json['error']['errors'] : [] as $error) {
            if (is_array($error) && is_string($error['reason'] ?? null)) {
                $reasons[] = $error['reason'];
            }
        }
        $limited = array_intersect($reasons, ['rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded', 'quotaExceeded']) !== [];
        $retryAfter = $response->header('Retry-After');
        $delay = null;
        if (is_string($retryAfter)) {
            $date = ctype_digit($retryAfter) ? false : strtotime($retryAfter);
            $delay = ctype_digit($retryAfter) ? min(86400, (int) $retryAfter) : ($date === false ? null : max(0, min(86400, $date - ($this->clock)())));
        }
        if ($delay === null && in_array('dailyLimitExceeded', $reasons, true)) { $delay = 86400; }
        $transient = $response->status === 429 || $response->status >= 500 || ($delivery && $response->status === 401) || ($response->status === 403 && $limited);
        throw new ApiFailure($delivery ? 'Gmail delivery failed.' : 'Gmail authorization failed.', $transient, $delay);
    }
}
