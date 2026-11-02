<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\WhatsApp;

use Broadcast\Application\WhatsAppGateway;
use Broadcast\Domain\WhatsAppFailure;
use Broadcast\Infrastructure\Http\HttpResponse;

final readonly class WppConnectClient implements WhatsAppGateway
{
    private WhatsAppHttpClient $http;
    private string $baseUrl;

    /** @param list<string> $allowedRecipients Digits-only phone numbers; an empty list denies every send. */
    public function __construct(
        string $baseUrl,
        private string $session,
        #[\SensitiveParameter] private string $token,
        private array $allowedRecipients,
        ?WhatsAppHttpClient $http = null,
    ) {
        $url = parse_url($baseUrl);
        $host = is_array($url) ? ($url['host'] ?? '') : '';
        $scheme = is_array($url) ? ($url['scheme'] ?? '') : '';
        if (!is_array($url) || !in_array($scheme, ['http', 'https'], true) || $host === ''
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl) || (isset($url['port']) && $url['port'] < 1)
            || ($scheme === 'http' && !in_array($host, ['127.0.0.1', '[::1]', 'localhost'], true))
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $session)
            || $token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('Invalid WhatsApp transport configuration.');
        }
        foreach ($allowedRecipients as $address) {
            if (!is_string($address) || !self::phone($address)) {
                throw new \InvalidArgumentException('Invalid WhatsApp transport configuration.');
            }
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? new CurlWhatsAppHttpClient();
    }

    public function connected(): bool
    {
        $data = $this->request('GET', '/status-session');
        return ($data['status'] ?? null) === 'CONNECTED' && ($data['session'] ?? null) === $this->session;
    }

    public function sendText(string $address, string $text): string
    {
        if (!self::phone($address) || !in_array($address, $this->allowedRecipients, true)
            || !mb_check_encoding($text, 'UTF-8') || preg_match('/\A\s*\z/u', $text) || str_contains($text, "\0")
            || mb_strlen($text, 'UTF-8') > 65536) {
            throw new WhatsAppFailure('permanent');
        }
        $body = json_encode(['phone' => $address, 'message' => $text, 'isGroup' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > 131072) {
            throw new WhatsAppFailure('permanent');
        }
        $data = $this->request('POST', '/send-message', $body);
        $results = $data['response'] ?? null;
        $result = is_array($results) && count($results) === 1 ? ($results[0] ?? null) : null;
        if (($data['status'] ?? null) !== 'success' || !is_array($result)
            || !is_string($result['id'] ?? null) || $result['id'] === '' || strlen($result['id']) > 128
            || !in_array($result['to'] ?? null, [$address . '@s.whatsapp.net', $address . '@c.us'], true)
            || ($result['type'] ?? null) !== 'chat') {
            throw new WhatsAppFailure('uncertain');
        }
        return $result['id'];
    }

    public function events(int $cursor, int $limit = 100): array
    {
        if ($cursor < 0 || $limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Invalid WhatsApp event range.');
        }
        $data = $this->request('GET', '/events?cursor=' . $cursor . '&limit=' . $limit);
        $events = $data['response'] ?? null;
        if (($data['status'] ?? null) !== 'success' || !is_array($events) || !array_is_list($events) || count($events) > $limit) {
            throw new WhatsAppFailure('transient');
        }
        $result = [];
        $previous = $cursor;
        foreach ($events as $event) {
            if (!is_array($event) || !is_int($event['sequence'] ?? null) || $event['sequence'] <= $previous
                || !is_string($event['id'] ?? null) || $event['id'] === '' || strlen($event['id']) > 64
                || !is_string($event['session'] ?? null) || !is_string($event['event'] ?? null)
                || !is_int($event['createdAt'] ?? null) || !is_array($event['data'] ?? null)) {
                throw new WhatsAppFailure('transient');
            }
            $previous = $event['sequence'];
            if ($event['session'] === $this->session) {
                $result[] = $event;
            }
        }
        return $result;
    }

    public function resolvePhone(string $address): ?string
    {
        if (preg_match('/\A([1-9][0-9]{6,14})(?:@(?:s\.whatsapp\.net|c\.us))?\z/', $address, $match)) {
            return '+' . $match[1];
        }
        if (!preg_match('/\A([0-9]{1,32})@lid\z/', $address, $match)) {
            return null;
        }
        $data = $this->request('GET', '/contact/pn-lid/' . rawurlencode($address));
        $lid = $data['lid'] ?? null;
        $pn = $data['phoneNumber'] ?? null;
        if (!is_array($lid) || !is_array($pn) || ($lid['server'] ?? '') !== 'lid'
            || ($lid['user'] ?? '') !== $match[1] || ($lid['id'] ?? '') !== $match[1]
            || !in_array($pn['server'] ?? null, ['s.whatsapp.net', 'c.us'], true)
            || !is_string($pn['user'] ?? null) || !self::phone($pn['user']) || ($pn['id'] ?? '') !== $pn['user']) {
            return null;
        }
        return '+' . $pn['user'];
    }

    private function request(string $method, string $path, ?string $body = null): array
    {
        $sending = $method === 'POST';
        try {
            $response = $this->http->request($method, $this->baseUrl . '/api/' . $this->session . $path,
                ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                $body, $sending ? 70 : 15);
        } catch (WhatsAppHttpFailure $failure) {
            throw new WhatsAppFailure($sending && $failure->requestMayHaveBeenSent ? 'uncertain' : 'unavailable');
        } catch (\Throwable) {
            throw new WhatsAppFailure($sending ? 'uncertain' : 'unavailable');
        }
        $data = strlen($response->body) <= CurlWhatsAppHttpClient::MAX_RESPONSE_BYTES ? json_decode($response->body, true, 64) : null;
        $this->checkStatus($response, is_array($data) ? $data : [], $sending);
        if (!is_array($data)) {
            throw new WhatsAppFailure($sending ? 'uncertain' : 'transient');
        }
        return $data;
    }

    private function checkStatus(HttpResponse $response, array $data, bool $sending): void
    {
        $status = $response->status;
        if ($status >= 200 && $status < 300) {
            return;
        }
        if (in_array($status, [401, 403], true) || ($status === 404 && ($data['status'] ?? '') === 'disconnected' && ($data['session'] ?? '') === $this->session)) {
            throw new WhatsAppFailure('unavailable');
        }
        if ($status === 429) {
            $retry = $response->header('Retry-After');
            throw new WhatsAppFailure('transient', $retry !== null && preg_match('/\A[0-9]{1,6}\z/', $retry) ? (int) $retry : null);
        }
        if ($status >= 500 || $status < 200 || ($status >= 300 && $status < 400) || $status === 408) {
            throw new WhatsAppFailure($sending ? 'uncertain' : 'unavailable');
        }
        throw new WhatsAppFailure('permanent');
    }

    private static function phone(string $value): bool
    {
        return (bool) preg_match('/\A[1-9][0-9]{6,14}\z/', $value);
    }
}
