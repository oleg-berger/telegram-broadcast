<?php
declare(strict_types=1);

namespace Broadcast\Domain;

use Broadcast\Application\Messages;

final class DirectoryContact
{
    public static function phone(mixed $value): string
    {
        $phone = is_string($value) ? PhoneNumber::normalize($value) : null;
        if ($phone === null) { throw new \InvalidArgumentException('Invalid directory phone.'); }
        return $phone;
    }

    public static function language(mixed $value): string
    {
        if (!is_string($value) || !isset(Messages::LANGUAGES[$value])) { throw new \InvalidArgumentException('Invalid directory language.'); }
        return $value;
    }

    public static function email(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n\x00]/', $value)) { throw new \InvalidArgumentException('Invalid directory email.'); }
        return $value;
    }
}
