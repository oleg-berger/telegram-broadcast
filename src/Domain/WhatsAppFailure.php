<?php
declare(strict_types=1);

namespace Broadcast\Domain;

final class WhatsAppFailure extends \RuntimeException
{
    public readonly string $category;
    public readonly ?int $retryAfter;

    public function __construct(string $category, ?int $retryAfter = null)
    {
        if (!in_array($category, ['unavailable', 'transient', 'permanent', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Invalid WhatsApp failure category.');
        }
        $this->category = $category;
        $this->retryAfter = $retryAfter === null ? null : max(0, $retryAfter);
        parent::__construct('WhatsApp operation ' . $category . '.');
    }
}
