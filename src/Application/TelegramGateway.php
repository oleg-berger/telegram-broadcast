<?php

declare(strict_types=1);

namespace Broadcast\Application;

interface TelegramGateway
{
    /** @param array<string, mixed> $options */
    public function sendMessage(int $chatId, string $text, array $options = []): void;

    public function sendPhoto(int $chatId, string $path, string $caption): void;

    /** @return list<array<string, mixed>> */
    public function getUpdates(int $offset, int $timeout = 25): array;

    public function answerCallbackQuery(string $id): void;

    public function sendDocument(int $chatId, string $path, string $filename): void;

    /** @param list<array{command: string, description: string}> $commands */
    public function setCommands(array $commands, array $scope = ['type' => 'default'], string $language = ''): void;
}
