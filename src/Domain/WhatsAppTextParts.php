<?php
declare(strict_types=1);

namespace Broadcast\Domain;

final class WhatsAppTextParts
{
    /** @return list<string> */
    public static function split(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new \InvalidArgumentException('Invalid WhatsApp text.');
        }
        $parts = [];
        while ($text !== '') {
            $part = mb_strcut($text, 0, 60000, 'UTF-8');
            // Control characters can expand sixfold in JSON; reserve space for the envelope.
            while (($bytes = strlen(json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) > 130800) {
                $part = mb_strcut($part, 0, (int) floor(strlen($part) * 130798 / $bytes), 'UTF-8');
            }
            if (strlen($part) < strlen($text)) {
                $paragraph = strrpos($part, "\n\n");
                if ($paragraph !== false && $paragraph > 0) {
                    $part = substr($part, 0, $paragraph + 2);
                }
            }
            $parts[] = $part;
            $text = substr($text, strlen($part));
        }
        return $parts;
    }
}
