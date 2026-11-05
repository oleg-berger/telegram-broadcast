<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Mail\GoogleOAuthFiles;
use Broadcast\Infrastructure\Runtime\Config;
use PHPUnit\Framework\TestCase;

final class GmailConfigTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gmail-config-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/client.json', '{"installed":{"client_id":"private-client","client_secret":"private-secret"}}');
        file_put_contents($this->directory . '/token.json', '{"refresh_token":"private-refresh"}');
        $this->environment = [
            'TELEGRAM_BOT_TOKEN' => 'token', 'TELEGRAM_ADMIN_IDS' => '99', 'TELEGRAM_SUPERADMIN_IDS' => '100',
            'TRANSLATOR_API_KEY' => 'key', 'TRANSLATOR_API_URL' => 'https://api-free.deepl.com', 'TRANSLATOR_MODEL' => 'model',
            'DATABASE_PATH' => '/data/broadcast.sqlite', 'MAIL_TRANSPORT' => 'gmail',
            'GMAIL_OAUTH_CLIENT_FILE' => $this->directory . '/client.json', 'GMAIL_OAUTH_TOKEN_FILE' => $this->directory . '/token.json',
            'MAIL_FROM_ADDRESS' => 'sender@gmail.com', 'MAIL_FROM_NAME' => 'Bot',
        ];
    }

    protected function tearDown(): void
    {
        unlink($this->directory . '/client.json');
        unlink($this->directory . '/token.json');
        rmdir($this->directory);
    }

    public function testGmailUsesOAuthFilesWithoutAnySmtpSettingsOrNetworkCalls(): void
    {
        $config = Config::fromArray($this->environment);
        self::assertSame('gmail', $config->mailTransport);
        self::assertSame([], $config->smtp);
        self::assertSame([
            'clientId' => 'private-client', 'clientSecret' => 'private-secret', 'refreshToken' => 'private-refresh',
            'fromAddress' => 'sender@gmail.com', 'fromName' => 'Bot', 'replyTo' => '',
        ], $config->gmail);
    }

    public function testFromEnvironmentLoadsMailSelectionAndFilePaths(): void
    {
        $previous = [];
        try {
            foreach ($this->environment as $key => $value) { $previous[$key] = getenv($key); putenv($key . '=' . $value); }
            self::assertSame('gmail', Config::fromEnvironment()->mailTransport);
            self::assertSame('private-refresh', Config::fromEnvironment()->gmail['refreshToken']);
        } finally {
            foreach ($previous as $key => $value) { putenv($value === false ? $key : $key . '=' . $value); }
        }
    }

    public function testWebClientFilesCanBeUsedForAnAlreadyAuthorizedToken(): void
    {
        file_put_contents($this->directory . '/client.json', '{"web":{"client_id":"private-client","client_secret":"private-secret"}}');
        self::assertSame('private-client', Config::fromArray($this->environment)->gmail['clientId']);
        try { GoogleOAuthFiles::client($this->directory . '/client.json', desktopOnly: true); self::fail('Desktop helper accepted a web client.'); } catch (\InvalidArgumentException $failure) {
            self::assertSame('Invalid Gmail OAuth files.', $failure->getMessage());
        }
    }

    public function testInvalidFilesProduceNoPathsOrSecretsInExceptions(): void
    {
        foreach (['{"type":"service_account","private_key":"private-secret"}', '{"installed":{"client_id":"private-client"}}', '{private-secret', 'null', '{"installed":{"client_id":"private-client","client_secret":"replace_with_secret"}}'] as $json) {
            file_put_contents($this->directory . '/client.json', $json);
            $this->assertInvalid($this->environment);
        }
        file_put_contents($this->directory . '/client.json', '{"installed":{"client_id":"client","client_secret":"secret"}}');
        file_put_contents($this->directory . '/token.json', '{"access_token":"private-access"}');
        $this->assertInvalid($this->environment);
    }

    public function testMissingFilesSettingsAndUnsafeSenderAreRejected(): void
    {
        foreach (['GMAIL_OAUTH_CLIENT_FILE', 'GMAIL_OAUTH_TOKEN_FILE', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'] as $key) {
            $this->assertInvalid(array_diff_key($this->environment, [$key => true]));
        }
        foreach ([['GMAIL_OAUTH_CLIENT_FILE' => $this->directory . '/private-missing.json'], ['GMAIL_OAUTH_CLIENT_FILE' => 'https://example.com/private.json'], ['MAIL_TRANSPORT' => 'private-unknown'], ['MAIL_FROM_ADDRESS' => 'private-invalid'], ['MAIL_FROM_NAME' => "Private\nBcc: other@example.com"], ['MAIL_REPLY_TO' => ['private']], ['MAIL_TRANSPORT' => 'smtp']] as $changes) {
            $this->assertInvalid(array_replace($this->environment, $changes));
        }
    }

    private function assertInvalid(array $environment): void
    {
        try { Config::fromArray($environment); self::fail('Invalid Gmail settings accepted.'); } catch (\InvalidArgumentException $failure) {
            self::assertSame('Invalid runtime configuration.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('private', $failure->getMessage());
            self::assertStringNotContainsString($this->directory, $failure->getMessage());
        }
    }
}
