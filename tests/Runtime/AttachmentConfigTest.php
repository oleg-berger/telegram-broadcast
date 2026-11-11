<?php
declare(strict_types=1);
namespace Broadcast\Tests\Runtime;
use Broadcast\Infrastructure\Runtime\AttachmentConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class AttachmentConfigTest extends TestCase
{
    public function testExistingRuntimeNeedsNoAdditionalSettings(): void
    {
        $config = AttachmentConfig::fromArray([]);
        self::assertNull($config->attachmentPath);
        self::assertNull($config->publicBaseUrl);
        self::assertSame(25_000_000, $config->mailMaxMessageBytes);
    }
    public function testOptionalStorageAndHttpsLinksAreParsedWithoutNetwork(): void
    {
        $config = AttachmentConfig::fromArray(['ATTACHMENTS_PATH'=>'var/files','ATTACHMENTS_PUBLIC_BASE_URL'=>'https://files.example.test/download','MAIL_MAX_MESSAGE_BYTES'=>'1234567']);
        self::assertSame('var/files', $config->attachmentPath);
        self::assertSame('https://files.example.test/download', $config->publicBaseUrl);
        self::assertSame(1234567, $config->mailMaxMessageBytes);
    }
    #[DataProvider('invalidSettings')]
    public function testInvalidConfigurationHasNoSecretInError(array $settings): void
    {
        try { AttachmentConfig::fromArray($settings); self::fail('Invalid config accepted.'); }
        catch (\InvalidArgumentException $error) { self::assertSame('Invalid attachment configuration.', $error->getMessage()); }
    }
    public static function invalidSettings(): array
    {
        return [[['ATTACHMENTS_PUBLIC_BASE_URL'=>'https://secret.example/download']], [['ATTACHMENTS_PATH'=>"invalid\0"]], [['ATTACHMENTS_PATH'=>'var/files','ATTACHMENTS_PUBLIC_BASE_URL'=>'http://secret.example/download']], [['ATTACHMENTS_PATH'=>'var/files','ATTACHMENTS_PUBLIC_BASE_URL'=>'https://secret:secret@example.test/download']], [['ATTACHMENTS_PATH'=>'var/files','ATTACHMENTS_PUBLIC_BASE_URL'=>'https://example.test/download?secret=yes']], [['MAIL_MAX_MESSAGE_BYTES'=>0]], [['MAIL_MAX_MESSAGE_BYTES'=>100_000_001]]];
    }
}
