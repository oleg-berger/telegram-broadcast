<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\Attachment;

interface AttachmentLinks
{
    public function issue(Attachment $attachment, int $contactId, int $now): string;
    public function resolve(string $token, int $now): ?Attachment;
    /** Revokes expired grants; blob removal requires separate dependency checks. */
    public function cleanup(int $now): int;
}
