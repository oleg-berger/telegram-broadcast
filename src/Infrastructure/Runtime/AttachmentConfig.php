<?php
declare(strict_types=1);
namespace Broadcast\Infrastructure\Runtime;

final readonly class AttachmentConfig
{
    private function __construct(public ?string $attachmentPath, public ?string $publicBaseUrl, public int $mailMaxMessageBytes, public ?string $linkKeyFile) {}

    public static function fromEnvironment(): self
    {
        $values = [];
        foreach (['ATTACHMENTS_PATH','ATTACHMENTS_PUBLIC_BASE_URL','MAIL_MAX_MESSAGE_BYTES','ATTACHMENTS_LINK_KEY_FILE'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') { $values[$key] = $value; }
        }
        return self::fromArray($values);
    }

    public static function fromArray(array $values): self
    {
        $path = $values['ATTACHMENTS_PATH'] ?? null;
        $url = $values['ATTACHMENTS_PUBLIC_BASE_URL'] ?? null;
        $keyFile = $values['ATTACHMENTS_LINK_KEY_FILE'] ?? null;
        if ($keyFile !== null && (!is_string($keyFile) || trim($keyFile) === '' || str_contains($keyFile, "\0"))) { throw new \InvalidArgumentException('Invalid attachment configuration.'); }
        if (($path !== null && (!is_string($path) || trim($path) === '' || str_contains($path, "\0")))
            || ($url !== null && ($path === null || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null))) { throw new \InvalidArgumentException('Invalid attachment configuration.'); }
        $limit = filter_var($values['MAIL_MAX_MESSAGE_BYTES'] ?? 25_000_000, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1024,'max_range'=>100_000_000]]);
        if ($limit === false) { throw new \InvalidArgumentException('Invalid attachment configuration.'); }
        return new self($path, $url, $limit, $keyFile);
    }

}
