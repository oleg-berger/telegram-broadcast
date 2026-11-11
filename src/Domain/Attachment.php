<?php
declare(strict_types=1);

namespace Broadcast\Domain;

final readonly class Attachment
{
    public const MAX_BYTES = 20_000_000;

    public function __construct(
        public string $storageKey,
        public string $displayName,
        public string $mime,
        public int $byteSize,
        public string $sha256,
    ) {
        self::validateKey($storageKey);
        self::validateName($displayName);
        if ($byteSize < 0 || $byteSize > self::MAX_BYTES || !preg_match('/\A[a-f0-9]{64}\z/', $sha256)
            || !preg_match('/\A[a-zA-Z0-9!#$&^_.+\-]+\/[a-zA-Z0-9!#$&^_.+\-]+\z/', $mime)) {
            throw new \InvalidArgumentException('Invalid attachment metadata.');
        }
    }

    public static function validateKey(string $key): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            throw new \InvalidArgumentException('Invalid attachment key.');
        }
    }

    public static function validateName(string $name): void
    {
        if ($name === '' || strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name) || in_array($name, ['.', '..'], true)) {
            throw new \InvalidArgumentException('Invalid attachment filename.');
        }
    }
}
