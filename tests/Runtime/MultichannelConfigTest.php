<?php
declare(strict_types=1);
namespace Broadcast\Tests\Runtime;
use Broadcast\Infrastructure\Runtime\MultichannelConfig;
use PHPUnit\Framework\TestCase;
final class MultichannelConfigTest extends TestCase
{
    public function testExistingRuntimeNeedsNoAdditionalSettings(): void
    {
        $config = MultichannelConfig::fromArray([]);
        self::assertNull($config->mysql);
        self::assertNull($config->attachmentPath);
        self::assertNull($config->linkKeyFile);
    }
    public function testDirectoryConfigurationIsParsedWithoutNetwork(): void
    {
        $config = MultichannelConfig::fromArray(['CONTACTS_MYSQL_DSN'=>'mysql:host=localhost;port=13306;dbname=broadcast_test;charset=utf8mb4','CONTACTS_MYSQL_USER'=>'test','CONTACTS_MYSQL_PASSWORD'=>'test-secret','ATTACHMENTS_PATH'=>'var/files','ATTACHMENTS_PUBLIC_BASE_URL'=>'https://files.example.test/download.php']);
        self::assertSame('test', $config->mysql['user']);
        self::assertSame(25_000_000, $config->mailMaxMessageBytes);
    }
    public function testIncompleteConfigurationHasNoSecretInError(): void
    {
        try { MultichannelConfig::fromArray(['CONTACTS_MYSQL_PASSWORD'=>'secret-marker']); self::fail('Accepted incomplete config'); }
        catch (\InvalidArgumentException $error) { self::assertSame('Invalid multichannel configuration.', $error->getMessage()); }
    }
}
