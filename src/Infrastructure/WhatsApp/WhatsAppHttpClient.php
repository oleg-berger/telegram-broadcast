<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\WhatsApp;

use Broadcast\Infrastructure\Http\HttpResponse;

interface WhatsAppHttpClient
{
    /** @param array<string,string> $headers */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse;
}
