<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\Attachment;

interface AttachmentMailGateway extends MailGateway
{
    /** @param list<array{attachment:Attachment,path:string}> $attachments */
    public function sendWithAttachments(string $to, string $subject, string $text, array $attachments): void;
}
