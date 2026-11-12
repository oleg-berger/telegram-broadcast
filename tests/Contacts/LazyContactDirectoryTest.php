<?php
declare(strict_types=1);
namespace Broadcast\Tests\Contacts;
use Broadcast\Infrastructure\Contacts\LazyContactDirectory;
use Broadcast\Domain\ApiFailure;
use PHPUnit\Framework\TestCase;
final class LazyContactDirectoryTest extends TestCase
{
    public function testOutageDoesNotPreventWorkerConstructionAndReconnectsOnNextOperation(): void
    {
        $calls = 0;
        $directory = new LazyContactDirectory(static function () use (&$calls): never { ++$calls; throw new \RuntimeException('sensitive upstream'); });
        self::assertSame(0, $calls);
        for ($i=0;$i<2;++$i) {
            try { $directory->deliveryAllowed(1,'whatsapp'); self::fail('Expected outage'); }
            catch (ApiFailure $failure) { self::assertTrue($failure->transient); self::assertStringNotContainsString('sensitive', $failure->getMessage()); }
        }
        self::assertSame(2, $calls);
    }
}
