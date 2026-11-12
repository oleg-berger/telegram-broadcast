<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Integration;

use Broadcast\Local\LocalStand;
use Broadcast\Application\WhatsAppGateway;
use Broadcast\Domain\WhatsAppFailure;
use Broadcast\Infrastructure\Runtime\ProcessLock;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/whatsapp/LocalStand.php';

final class LocalScenarioTest extends TestCase
{
    public function testInitializationLocksEveryLocalWriterBeforeTouchingDatabase(): void
    {
        foreach (['worker', 'whatsapp-poll', 'local-staff'] as $role) {
            $directory = sys_get_temp_dir() . '/whatsapp-init-' . bin2hex(random_bytes(8));
            $lock = ProcessLock::acquire($directory . '/runtime', $role);
            try {
                try {
                    LocalStand::initialize($directory);
                    self::fail('An active local writer must block initialization.');
                } catch (\RuntimeException $failure) {
                    self::assertSame('Another ' . $role . ' process is already running.', $failure->getMessage());
                }
                self::assertFileDoesNotExist($directory . '/application.sqlite');
            } finally {
                $lock->release();
                foreach (glob($directory . '/runtime/*') as $path) { unlink($path); }
                rmdir($directory . '/runtime');
                rmdir($directory);
            }
        }
    }

    public function testPollingOutageDoesNotBlockOtherChannelsInLocalStand(): void
    {
        $directory = sys_get_temp_dir() . '/whatsapp-outage-' . bin2hex(random_bytes(8));
        $gateway = new class implements WhatsAppGateway {
            public function connected(): bool { return false; }
            public function sendText(string $address, string $text): string { throw new \LogicException('Unexpected send.'); }
            public function events(int $cursor, int $limit = 100): array { throw new WhatsAppFailure('unavailable'); }
            public function resolvePhone(string $address): ?string { return null; }
        };
        $stand = new LocalStand($directory, $gateway);
        try {
            $stand->store->queueReply(99, 'Other channels continue');
            for ($i = 0; $i < 2; ++$i) {
                try { $stand->pump(); } catch (WhatsAppFailure) {}
            }
            self::assertSame('Other channels continue', $stand->capture->messages('telegram')[0]['text'] ?? null);
        } finally {
            $stand->store->close();
            unset($stand);
            foreach (glob($directory . '/*') as $path) { unlink($path); }
            rmdir($directory);
        }
    }

    public function testCompleteStandaloneDemonstrationUsesSharedApplicationAndNoRealApis(): void
    {
        $directory = sys_get_temp_dir() . '/whatsapp-demo-' . bin2hex(random_bytes(8));
        $stand = new LocalStand($directory);
        try {
            self::assertSame(['registration' => true, 'staff_approval' => true, 'broadcast_approval' => true,
                'translated_delivery' => true, 'report' => true, 'confirmed_link' => true,
                'new_channel_only_catchup' => true, 'email_count' => 1], $stand->scenario());
            self::assertFileExists($directory . '/inbox.html');
        } finally {
            $stand->store->close();
            unset($stand);
            foreach (glob($directory . '/*') as $path) { unlink($path); }
            rmdir($directory);
        }
    }
}
