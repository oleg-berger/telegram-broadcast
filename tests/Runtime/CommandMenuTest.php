<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Application\TelegramGateway;
use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Runtime\CommandMenu;
use Broadcast\Infrastructure\Runtime\Config;
use PHPUnit\Framework\TestCase;

final class CommandMenuTest extends TestCase
{
    public function testInstallSetsUserAndStaffMenusWithRetry(): void
    {
        $gateway = new FakeMenuGateway();
        $gateway->transientFailures = 1;
        $config = Config::fromArray([
            'TELEGRAM_BOT_TOKEN' => 'token',
            'TELEGRAM_ADMIN_IDS' => '42',
            'TELEGRAM_SUPERADMIN_IDS' => '900719',
            'TRANSLATOR_API_KEY' => 'key',
            'TRANSLATOR_API_URL' => 'https://api-free.deepl.com',
            'DATABASE_PATH' => '/data/app.sqlite',
            'SMTP_HOST' => 'smtp.example.com',
            'SMTP_PORT' => '587',
            'SMTP_ENCRYPTION' => 'starttls',
            'SMTP_USERNAME' => 'bot@example.com',
            'SMTP_PASSWORD' => 'secret',
            'MAIL_FROM_ADDRESS' => 'bot@example.com',
            'MAIL_FROM_NAME' => 'Bot',
        ]);

        (new CommandMenu())->install($gateway, $config);

        // 5 user menus + 2 staff chats × 5 languages succeed; the transient failure is retried.
        self::assertCount(5 + 2 * 5, $gateway->calls);
        self::assertSame(1, $gateway->failedAttempts);
        self::assertSame('default', $gateway->calls[0][1]['type']);
        $staff = array_filter($gateway->calls, fn ($call) => $call[1]['type'] === 'chat' && $call[1]['chat_id'] === 900719);
        self::assertNotEmpty($staff);
        $commands = array_column((array) array_values($staff)[0][0], 'command');
        self::assertContains('unsubscribe', $commands);
        self::assertContains('userdata', $commands);
        $admin = array_filter($gateway->calls, fn ($call) => $call[1]['type'] === 'chat' && $call[1]['chat_id'] === 42);
        $adminCommands = array_column((array) array_values($admin)[0][0], 'command');
        self::assertNotContains('unsubscribe', $adminCommands);
        self::assertNotContains('userdata', $adminCommands);
    }

    public function testInstallGivesUpOnPermanentFailure(): void
    {
        $gateway = new FakeMenuGateway();
        $gateway->permanent = true;
        $config = Config::fromArray([
            'TELEGRAM_BOT_TOKEN' => 'token',
            'TELEGRAM_ADMIN_IDS' => '42',
            'TELEGRAM_SUPERADMIN_IDS' => '900719',
            'TRANSLATOR_API_KEY' => 'key',
            'TRANSLATOR_API_URL' => 'https://api-free.deepl.com',
            'DATABASE_PATH' => '/data/app.sqlite',
            'SMTP_HOST' => 'smtp.example.com',
            'SMTP_PORT' => '587',
            'SMTP_ENCRYPTION' => 'starttls',
            'SMTP_USERNAME' => 'bot@example.com',
            'SMTP_PASSWORD' => 'secret',
            'MAIL_FROM_ADDRESS' => 'bot@example.com',
            'MAIL_FROM_NAME' => 'Bot',
        ]);

        $this->expectException(ApiFailure::class);
        (new CommandMenu())->install($gateway, $config);
    }
}

final class FakeMenuGateway implements TelegramGateway
{
    public array $calls = [];
    public int $failedAttempts = 0;
    public int $transientFailures = 0;
    public bool $permanent = false;
    public function sendMessage(int $chatId, string $text, array $options = []): void {}
    public function getUpdates(int $offset, int $timeout = 25): array { return []; }
    public function answerCallbackQuery(string $id): void {}
    public function sendDocument(int $chatId, string $path, string $filename): void {}
    public function sendPhoto(int $chatId, string $path, string $caption): void {}
    public function setCommands(array $commands, array $scope = ['type' => 'default'], string $language = ''): void
    {
        if ($this->permanent) { $this->failedAttempts++; throw new ApiFailure('permanent'); }
        if ($this->transientFailures > 0) { $this->transientFailures--; $this->failedAttempts++; throw new ApiFailure('busy', true, 0); }
        $this->calls[] = [$commands, $scope, $language];
    }
}
