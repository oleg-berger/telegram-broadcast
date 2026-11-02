<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Integration;

use Broadcast\Infrastructure\Runtime\Config;
use PHPUnit\Framework\TestCase;

final class WhatsAppConfigTest extends TestCase
{
    private function environment(): array
    {
        return ['TELEGRAM_BOT_TOKEN' => '123:test', 'TELEGRAM_ADMIN_IDS' => '98', 'TELEGRAM_SUPERADMIN_IDS' => '99',
            'TRANSLATOR_API_KEY' => 'test-key', 'TRANSLATOR_API_URL' => 'https://api-free.deepl.com',
            'DATABASE_PATH' => sys_get_temp_dir() . '/test.sqlite', 'APP_ENV' => 'test',
            'MAIL_FROM_ADDRESS' => 'test@example.test', 'MAIL_FROM_NAME' => 'Local test',
            'SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => '1025', 'SMTP_ENCRYPTION' => 'none'];
    }

    public function testWhatsAppIsDisabledByDefaultWithoutReadingAnySecretFile(): void
    {
        $config = Config::fromArray($this->environment() + ['WHATSAPP_TOKEN_FILE' => '/does-not-exist']);
        self::assertSame([], $config->whatsapp);
    }

    public function testEnabledWhatsAppReadsPrivateTokenAndRecipientListWithoutNetwork(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wa-token-');
        $recipients = tempnam(sys_get_temp_dir(), 'wa-allowed-');
        try {
            file_put_contents($file, json_encode(['session' => 'local-test', 'token' => 'private-token']));
            file_put_contents($recipients, json_encode(['447700900123']));
            $config = Config::fromArray($this->environment() + ['WHATSAPP_ENABLED' => 'true',
                'WHATSAPP_URL' => 'http://127.0.0.1:21465', 'WHATSAPP_SESSION' => 'local-test',
                'WHATSAPP_TOKEN_FILE' => $file, 'WHATSAPP_ALLOWED_RECIPIENTS_FILE' => $recipients]);
            self::assertSame('local-test', $config->whatsapp['session']);
            self::assertSame(['447700900123'], $config->whatsapp['allowedRecipients']);
            self::assertSame('private-token', $config->whatsapp['token']);
        } finally { unlink($file); unlink($recipients); }
    }

    public function testWrongSessionEmptyAllowlistAndRemoteHttpFailWithoutSecretLeak(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wa-token-');
        $recipients = tempnam(sys_get_temp_dir(), 'wa-allowed-');
        try {
            file_put_contents($file, json_encode(['session' => 'local-test', 'token' => 'PRIVATE_SECRET']));
            file_put_contents($recipients, json_encode(['447700900123']));
            $base = $this->environment() + ['WHATSAPP_ENABLED' => 'true', 'WHATSAPP_URL' => 'http://127.0.0.1:21465',
                'WHATSAPP_SESSION' => 'local-test', 'WHATSAPP_TOKEN_FILE' => $file, 'WHATSAPP_ALLOWED_RECIPIENTS_FILE' => $recipients];
            foreach ([['WHATSAPP_SESSION' => 'different'], ['WHATSAPP_URL' => 'http://example.com:21465'], ['WHATSAPP_ENABLED' => 'yes']] as $changes) {
                try {
                    Config::fromArray(array_replace($base, $changes));
                    self::fail('Unsafe configuration accepted.');
                } catch (\InvalidArgumentException $failure) {
                    self::assertSame('Invalid runtime configuration.', $failure->getMessage());
                }
            }
            file_put_contents($recipients, '[]');
            $this->expectException(\InvalidArgumentException::class);
            Config::fromArray($base);
        } finally { unlink($file); unlink($recipients); }
    }
}
