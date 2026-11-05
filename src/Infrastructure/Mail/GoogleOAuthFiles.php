<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Mail;

use InvalidArgumentException;
use Throwable;

final class GoogleOAuthFiles
{
    public static function client(string $path, bool $desktopOnly = false): array
    {
        $json = self::read($path);
        $client = $json['installed'] ?? ($desktopOnly ? null : ($json['web'] ?? null));
        if (!is_array($client)) {
            throw new InvalidArgumentException('Invalid Gmail OAuth files.');
        }
        return ['clientId' => self::value($client, 'client_id'), 'clientSecret' => self::value($client, 'client_secret')];
    }

    public static function refreshToken(string $path): string
    {
        return self::value(self::read($path), 'refresh_token');
    }

    public static function value(array $json, string $key): string
    {
        $value = $json[$key] ?? null;
        if (!is_string($value) || $value === '' || trim($value) !== $value || preg_match('/[\r\n]/', $value) || str_starts_with($value, 'replace_with_')) {
            throw new InvalidArgumentException('Invalid Gmail OAuth files.');
        }
        return $value;
    }

    private static function read(string $path): array
    {
        try {
            if ($path === '' || str_contains($path, '://') || !is_file($path) || !is_readable($path) || filesize($path) > 1048576) {
                throw new InvalidArgumentException();
            }
            $json = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($json)) {
                throw new InvalidArgumentException();
            }
            return $json;
        } catch (Throwable) {
            // File errors and JSON can contain paths or secrets; do not chain the original exception.
            throw new InvalidArgumentException('Invalid Gmail OAuth files.');
        }
    }
}
