<?php
declare(strict_types=1);

namespace Broadcast\Application;

interface WhatsAppGateway
{
    public function connected(): bool;
    /** Returns the transport acceptance ID, not a delivery confirmation. */
    public function sendText(string $address, string $text): string;
    /** @return list<array{sequence:int,id:string,session:string,event:string,createdAt:int,data:array}> */
    public function events(int $cursor, int $limit = 100): array;
    /** A verified +E164 number, or null when the opaque address has no known mapping. */
    public function resolvePhone(string $address): ?string;
}
