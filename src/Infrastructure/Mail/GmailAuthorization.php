<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Mail;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\CurlHttpClient;
use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpTransportException;

/** One-time local operator setup; never used to send broadcast jobs. */
final class GmailAuthorization
{
    public const string SCOPE = 'https://www.googleapis.com/auth/gmail.send';
    private string $state;
    private string $verifier;
    private HttpClient $http;

    public function __construct(private string $clientId, private string $clientSecret, ?HttpClient $http = null)
    {
        $this->state = bin2hex(random_bytes(24));
        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->http = $http ?? new CurlHttpClient();
    }

    public function url(string $redirectUri): string
    {
        self::validateRedirect($redirectUri);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code',
            'scope' => self::SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $this->state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public function callback(string $target): string
    {
        $parts = parse_url($target);
        if (!is_array($parts) || ($parts['path'] ?? '') !== '/oauth2callback') {
            throw new ApiFailure('Invalid Gmail authorization callback.');
        }
        parse_str($parts['query'] ?? '', $query);
        if (!is_string($query['state'] ?? null) || !hash_equals($this->state, $query['state']) || isset($query['error'])
            || !is_string($query['code'] ?? null) || $query['code'] === '' || preg_match('/[\r\n]/', $query['code'])) {
            throw new ApiFailure('Invalid Gmail authorization callback.');
        }
        return $query['code'];
    }

    public function exchange(string $code, string $redirectUri): string
    {
        self::validateRedirect($redirectUri);
        try {
            $response = $this->http->post('https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
                'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'code' => $code,
                'code_verifier' => $this->verifier, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri,
            ], encoding_type: PHP_QUERY_RFC3986), 20);
        } catch (HttpTransportException $failure) {
            $reason = match ($failure->transportCode) {
                5, 6 => 'dns',
                7 => 'connection',
                28 => 'timeout',
                60, 77 => 'tls',
                default => $failure->timeout ? 'timeout' : 'transport',
            };
            throw new GmailAuthorizationFailure($reason);
        }
        $json = json_decode($response->body, true);
        if ($response->status !== 200) {
            $reason = match (is_array($json) ? ($json['error'] ?? null) : null) {
                'invalid_grant' => 'invalid_grant',
                'invalid_client', 'unauthorized_client' => 'invalid_client',
                default => 'http',
            };
            throw new GmailAuthorizationFailure($reason, $response->status);
        }
        if (!is_array($json)) {
            throw new GmailAuthorizationFailure('response');
        }
        $scopes = is_string($json['scope'] ?? null) ? explode(' ', $json['scope']) : [];
        if (!in_array(self::SCOPE, $scopes, true)) {
            throw new GmailAuthorizationFailure('scope');
        }
        try {
            return GoogleOAuthFiles::value(is_array($json) ? $json : [], 'refresh_token');
        } catch (\InvalidArgumentException) {
            throw new GmailAuthorizationFailure('refresh_token');
        }
    }

    private static function validateRedirect(string $uri): void
    {
        if (!preg_match('#^http://127\.0\.0\.1:([0-9]+)/oauth2callback$#D', $uri, $match) || (int) $match[1] < 1 || (int) $match[1] > 65535) {
            throw new \InvalidArgumentException('Invalid Gmail loopback redirect.');
        }
    }
}
