<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Http;

use Broadcast\Application\Translator;
use Broadcast\Domain\ApiFailure;
use InvalidArgumentException;
use JsonException;

final class DeepLTranslator implements Translator
{
    private readonly HttpClient $http;
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        ?HttpClient $http = null,
    ) {
        $this->baseUrl = $this->validateBaseUrl($baseUrl);
        $this->http = $http ?? new CurlHttpClient();
    }

    public function translate(string $text, string $targetLanguage): string
    {
        try {
            $body = json_encode([
                'text' => [$text],
                'target_lang' => strtoupper($targetLanguage),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ApiFailure('DeepL request could not be encoded.');
        }

        try {
            $response = $this->http->post(
                $this->baseUrl . '/v2/translate',
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                ],
                $body,
                30,
            );
        } catch (HttpTransportException $exception) {
            throw new ApiFailure(
                $exception->timeout ? 'DeepL request timed out.' : 'DeepL request failed.',
                true,
            );
        }

        if ($response->status !== 200) {
            $retryAfter = $this->retryAfter($response);
            throw new ApiFailure(
                'DeepL API request failed.',
                $response->status === 429 || $response->status >= 500,
                $retryAfter,
            );
        }

        try {
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiFailure('DeepL returned an invalid response.', true);
        }

        $translation = is_array($decoded) ? ($decoded['translations'][0]['text'] ?? null) : null;
        if (!is_string($translation) || trim($translation) === '' || count($decoded['translations']) !== 1) {
            throw new ApiFailure('DeepL returned an invalid response.', true);
        }

        return $translation;
    }

    private function validateBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $host = is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : null;
        $allowedHost = in_array($host, ['api.deepl.com', 'api-free.deepl.com'], true);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !$allowedHost
            || ($path !== '' && $path !== '/')
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('DeepL base URL is not allowed.');
        }

        return 'https://' . $host;
    }

    private function retryAfter(HttpResponse $response): ?int
    {
        $value = $response->header('Retry-After');
        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
