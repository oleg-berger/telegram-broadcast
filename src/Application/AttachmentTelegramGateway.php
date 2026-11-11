<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\Attachment;

interface AttachmentTelegramGateway extends TelegramGateway
{
    public function filePath(string $fileId): string;
    public function downloadFile(string $remotePath, string $targetPath, int $maxBytes = 20_000_000): void;
    public function sendAttachment(int $chatId, Attachment $attachment, string $path): void;
}
