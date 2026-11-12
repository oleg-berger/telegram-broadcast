<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\WhatsApp;

final class WhatsAppHttpFailure extends \RuntimeException
{
    public function __construct(public readonly bool $requestMayHaveBeenSent = true)
    {
        parent::__construct('WhatsApp HTTP request failed.');
    }
}
