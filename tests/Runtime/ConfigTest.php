<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Runtime\Config;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParsesRequiredEnvironmentWithoutChangingSecretValues(): void
    {
        $config = Config::fromArray([
            'TELEGRAM_BOT_TOKEN' => '123456:secret-token',
            'TELEGRAM_ADMIN_IDS' => '42,900719',
            'TELEGRAM_SUPERADMIN_IDS' => '900719',
            'ADMINS_CAN_APPROVE_USERS' => 'true',
            'TRANSLATOR_API_KEY' => 'deepseek-secret',
            'TRANSLATOR_API_URL' => 'https://api.deepseek.com',
            'TRANSLATOR_MODEL' => 'deepseek-chat',
            'TRANSLATOR_TEMPERATURE' => '0.3',
            'DATABASE_PATH' => '/data/app.sqlite',
            'SMTP_HOST' => 'smtp.example.com',
            'SMTP_PORT' => '587',
            'SMTP_ENCRYPTION' => 'starttls',
            'SMTP_USERNAME' => 'bot@example.com',
            'SMTP_PASSWORD' => 'smtp-secret',
            'MAIL_FROM_ADDRESS' => 'bot@example.com',
            'MAIL_FROM_NAME' => 'Broadcast Bot',
            'MAIL_REPLY_TO' => 'support@example.com',
            'MAIL_SEND_INTERVAL_SECONDS' => '5',
        ]);

        self::assertSame('123456:secret-token', $config->telegramToken);
        self::assertSame([42, 900719], $config->adminIds);
        self::assertSame([900719], $config->superadminIds);
        self::assertTrue($config->adminsCanApproveUsers);
        self::assertFalse($config->adminsCanExportUsers);
        self::assertSame('deepseek-secret', $config->translatorApiKey);
        self::assertSame('https://api.deepseek.com', $config->translatorApiUrl);
        self::assertSame('deepseek-chat', $config->translatorModel);
        self::assertSame(0.3, $config->translatorTemperature);
        self::assertSame('/data/app.sqlite', $config->databasePath);
        self::assertSame('/data' . DIRECTORY_SEPARATOR . 'backups', $config->backupDirectory);
        self::assertSame('/data' . DIRECTORY_SEPARATOR . 'runtime', $config->runtimeDirectory);
        self::assertSame([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => 'bot@example.com',
            'password' => 'smtp-secret',
            'fromAddress' => 'bot@example.com',
            'fromName' => 'Broadcast Bot',
            'replyTo' => 'support@example.com',
            'test' => false,
        ], $config->smtp);
        self::assertSame(5, $config->mailSendIntervalSeconds);
    }

    public function testMailSettingsHaveSafeDefaults(): void
    {
        $config = Config::fromArray(self::validEnvironment());

        self::assertSame('', $config->smtp['replyTo']);
        self::assertSame(2, $config->mailSendIntervalSeconds);
        self::assertFalse($config->smtp['test']);
        self::assertNull($config->translatorTemperature);
    }

    public function testTestEnvironmentAllowsPlainLocalSmtpWithoutCredentials(): void
    {
        $config = Config::fromArray([
            ...array_diff_key(self::validEnvironment(), ['SMTP_USERNAME' => true, 'SMTP_PASSWORD' => true]),
            'APP_ENV' => 'test',
            'SMTP_ENCRYPTION' => 'none',
        ]);

        self::assertTrue($config->smtp['test']);
        self::assertSame('none', $config->smtp['encryption']);
        self::assertSame('', $config->smtp['username']);
        self::assertSame('', $config->smtp['password']);
    }

    #[DataProvider('invalidEnvironment')]
    public function testRejectsInvalidEnvironmentWithASecretFreeMessage(array $environment): void
    {
        try {
            Config::fromArray($environment);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Invalid runtime configuration.', $exception->getMessage());
            self::assertStringNotContainsString('leaked-secret', $exception->getMessage());
        }
    }

    public static function invalidEnvironment(): iterable
    {
        $valid = self::validEnvironment();

        foreach (array_keys($valid) as $key) {
            yield "missing {$key}" => [array_diff_key($valid, [$key => true])];
        }
        foreach (['', '0', '-1', '1, 2', '1,', '1,two', '9223372036854775808'] as $ids) {
            yield "admin ids {$ids}" => [[...$valid, 'TELEGRAM_ADMIN_IDS' => $ids]];
            yield "superadmin ids {$ids}" => [[...$valid, 'TELEGRAM_SUPERADMIN_IDS' => $ids]];
        }
        yield 'placeholder token' => [[...$valid, 'TELEGRAM_BOT_TOKEN' => 'replace_with_token']];
        yield 'app env' => [[...$valid, 'APP_ENV' => 'development']];
        yield 'approve flag' => [[...$valid, 'ADMINS_CAN_APPROVE_USERS' => 'yes']];
        yield 'export flag' => [[...$valid, 'ADMINS_CAN_EXPORT_USERS' => '1']];
        yield 'smtp port zero' => [[...$valid, 'SMTP_PORT' => '0']];
        yield 'smtp port text' => [[...$valid, 'SMTP_PORT' => 'starttls']];
        yield 'smtp encryption' => [[...$valid, 'SMTP_ENCRYPTION' => 'ssl']];
        yield 'plain smtp in production' => [[...$valid, 'SMTP_ENCRYPTION' => 'none']];
        yield 'smtp host injection' => [[...$valid, 'SMTP_HOST' => 'smtp.example.com;icky']];
        yield 'from address' => [[...$valid, 'MAIL_FROM_ADDRESS' => 'not-an-email']];
        yield 'reply-to address' => [[...$valid, 'MAIL_REPLY_TO' => 'not-an-email']];
        yield 'from name newline' => [[...$valid, 'MAIL_FROM_NAME' => "Bot\nBcc: x@y.z"]];
        yield 'translator url http' => [[...$valid, 'TRANSLATOR_API_URL' => 'http://api.deepseek.com']];
        yield 'translator url credentials' => [[...$valid, 'TRANSLATOR_API_URL' => 'https://user:pass@api.deepseek.com']];
        yield 'translator url missing host' => [[...$valid, 'TRANSLATOR_API_URL' => 'https:///v1']];
        yield 'translator temperature text' => [[...$valid, 'TRANSLATOR_TEMPERATURE' => 'abc']];
        yield 'translator temperature negative' => [[...$valid, 'TRANSLATOR_TEMPERATURE' => '-1']];
        yield 'translator temperature high' => [[...$valid, 'TRANSLATOR_TEMPERATURE' => '3']];
        yield 'mail interval zero' => [[...$valid, 'MAIL_SEND_INTERVAL_SECONDS' => '0']];
        yield 'mail interval negative' => [[...$valid, 'MAIL_SEND_INTERVAL_SECONDS' => '-5']];
        yield 'mail interval huge' => [[...$valid, 'MAIL_SEND_INTERVAL_SECONDS' => '90000']];
    }

    private static function validEnvironment(): array
    {
        return [
            'TELEGRAM_BOT_TOKEN' => 'leaked-secret',
            'TELEGRAM_ADMIN_IDS' => '1',
            'TELEGRAM_SUPERADMIN_IDS' => '2',
            'TRANSLATOR_API_KEY' => 'leaked-secret',
            'TRANSLATOR_API_URL' => 'https://api.deepseek.com',
            'TRANSLATOR_MODEL' => 'deepseek-chat',
            'DATABASE_PATH' => '/data/app.sqlite',
            'SMTP_HOST' => 'smtp.example.com',
            'SMTP_PORT' => '587',
            'SMTP_ENCRYPTION' => 'starttls',
            'SMTP_USERNAME' => 'bot@example.com',
            'SMTP_PASSWORD' => 'leaked-secret',
            'MAIL_FROM_ADDRESS' => 'bot@example.com',
            'MAIL_FROM_NAME' => 'Broadcast Bot',
        ];
    }
}
