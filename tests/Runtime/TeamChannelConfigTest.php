<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Runtime\Config;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TeamChannelConfigTest extends TestCase
{
    private static function environment(): array
    {
        return ['TELEGRAM_BOT_TOKEN' => 'private-token', 'TELEGRAM_ADMIN_IDS' => '99', 'TELEGRAM_SUPERADMIN_IDS' => '100',
            'TRANSLATOR_API_KEY' => 'private-key', 'TRANSLATOR_API_URL' => 'https://api-free.deepl.com', 'TRANSLATOR_MODEL' => 'test-model',
            'DATABASE_PATH' => '/data/test.sqlite', 'APP_ENV' => 'test', 'MAIL_TRANSPORT' => 'smtp', 'SMTP_HOST' => 'localhost', 'SMTP_PORT' => '1025',
            'SMTP_ENCRYPTION' => 'none', 'MAIL_FROM_ADDRESS' => 'bot@example.com', 'MAIL_FROM_NAME' => 'Test'];
    }

    public function testChannelIsOptionalAndRequiresFullNegativeId(): void
    {
        self::assertNull(Config::fromArray(self::environment())->teamChannelId);
        self::assertNull(Config::fromArray([...self::environment(), 'TELEGRAM_TEAM_CHANNEL_ID' => ''])->teamChannelId);
        self::assertSame(-1004293904431, Config::fromArray([...self::environment(), 'TELEGRAM_TEAM_CHANNEL_ID' => '-1004293904431'])->teamChannelId);
    }

    #[DataProvider('invalidIds')]
    public function testInvalidChannelConfigurationHasNoSensitiveError(mixed $id): void
    {
        try {
            Config::fromArray([...self::environment(), 'TELEGRAM_TEAM_CHANNEL_ID' => $id]);
            self::fail('Invalid channel ID accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('Invalid runtime configuration.', $failure->getMessage());
            self::assertStringNotContainsString('private-', $failure->getMessage());
        }
    }

    public static function invalidIds(): array
    {
        return array_map(static fn (mixed $id): array => [$id], ['1004293904431', '0', '-1', '@channel', ' -1004293904431',
            '-1004293904431 ', '-1000', '-10001', '-1009999999999999999999', '-1001234;bad', 'replace_with_channel', 123, false]);
    }

    public function testChannelIsReadFromEnvironment(): void
    {
        $environment = [...self::environment(), 'TELEGRAM_TEAM_CHANNEL_ID' => '-1004293904431'];
        $previous = [];
        foreach ($environment as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            self::assertSame(-1004293904431, Config::fromEnvironment()->teamChannelId);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
