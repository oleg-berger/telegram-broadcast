<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Http;

use RuntimeException;

final class HttpTransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timeout = false, public readonly ?int $transportCode = null)
    {
        parent::__construct($message);
    }
}
