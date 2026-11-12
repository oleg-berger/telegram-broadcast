<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Transport;

use Broadcast\Domain\WhatsAppTextParts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppTextPartsTest extends TestCase
{
    #[DataProvider('longTexts')]
    public function testEveryCharacterSurvivesWithinNativeScalarAndJsonLimits(string $text): void
    {
        $parts = WhatsAppTextParts::split($text);
        self::assertGreaterThan(1, count($parts));
        self::assertSame($text, implode('', $parts));
        foreach ($parts as $part) {
            self::assertTrue(mb_check_encoding($part, 'UTF-8'));
            self::assertLessThanOrEqual(60000, strlen($part));
            self::assertLessThanOrEqual(65536, mb_strlen($part, 'UTF-8'));
            self::assertLessThanOrEqual(131072, strlen(json_encode(['phone' => '77010000001', 'message' => $part, 'isGroup' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        }
    }

    public static function longTexts(): array
    {
        return [[str_repeat("Привет café 👩🏽‍💻\n\n", 9000)], [str_repeat('a', 130000)], [str_repeat("\x01\t\n\"\\", 20000)]];
    }

    public function testShortAndEmptyInputsAreLossless(): void
    {
        self::assertSame([], WhatsAppTextParts::split(''));
        self::assertSame(["  café\n"], WhatsAppTextParts::split("  café\n"));
    }

    #[DataProvider('invalidTexts')]
    public function testUnsupportedTextFailsBeforeQueueCanSendBrokenParts(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WhatsAppTextParts::split($text);
    }

    public static function invalidTexts(): array
    {
        return [["bad\xFF"], ["bad\0text"]];
    }
}
