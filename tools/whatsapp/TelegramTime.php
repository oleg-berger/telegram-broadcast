<?php
declare(strict_types=1);

namespace Broadcast\Local;

use Broadcast\Infrastructure\Http\HttpClient;

/** Operator preflight: use the same clock as incoming Telegram message dates. */
final class TelegramTime
{
    public static function cutoff(HttpClient $http, #[\SensitiveParameter] string $token): int
    {
        try {
            $response = $http->post('https://api.telegram.org/bot' . $token . '/getMe',
                ['Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'], '{}', 15);
            $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            $text = $response->header('Date') ?? '';
            $format = 'D, d M Y H:i:s \G\M\T';
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $text, new \DateTimeZone('UTC'));
            if ($response->status !== 200 || ($data['ok'] ?? null) !== true || $date === false
                || $date->format($format) !== $text || $date->getTimestamp() < 1) {
                throw new \RuntimeException('Invalid clock response.');
            }
            return $date->getTimestamp();
        } catch (\Throwable) {
            throw new \RuntimeException('Telegram time check failed.');
        }
    }
}
