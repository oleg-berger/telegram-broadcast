<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\Attachment;

interface AttachmentWhatsAppGateway extends WhatsAppGateway
{
    public function supportsFile(Attachment $attachment): bool;
    public function sendFile(string $address, Attachment $attachment, string $path): string;
}
