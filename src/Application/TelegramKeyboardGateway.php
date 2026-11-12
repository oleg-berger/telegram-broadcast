<?php
declare(strict_types=1);

namespace Broadcast\Application;

interface TelegramKeyboardGateway
{
    public function editKeyboard(int $chatId, int $messageId, array $rows): void;
}
