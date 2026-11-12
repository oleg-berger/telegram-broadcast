<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\Attachment;

interface AttachmentStorage
{
    public function put(string $sourcePath, string $displayName): Attachment;
    public function path(string $storageKey): string;
    public function delete(string $storageKey): void;
}
