<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
use Broadcast\Application\Messages;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\PhoneNumber;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationNavigationTest extends TestCase
{
    #[DataProvider('countries')]
    public function testCountryUsesFullNumber(string $phone, ?string $expected): void
    {
        self::assertSame($expected, PhoneNumber::country($phone, 'EN-GB'));
    }

    public static function countries(): array
    {
        return [
            ['+442079460018', 'United Kingdom'],
            ['+77011234567', 'Kazakhstan'],
            ['+74951234567', 'Russia'],
            ['+12025550123', 'United States'],
            ['+34612345678', 'Spain'],
            ['+447700900123', null],
            ['+80012345678', null],
        ];
    }

    public function testInlineScreensRemoveReplyKeyboardAndCountryRequiresConfirmation(): void
    {
        foreach (array_keys(Messages::LANGUAGES) as $language) {
            $store = new SqliteStore(':memory:');
            $store->migrate();
            $bot = new Kernel($store, [99], [100]);
            $update = 0;
            $say = function (string $text) use ($bot, &$update): void {
                $bot->handle(['update_id' => ++$update, 'message' => ['from' => ['id' => 1], 'chat' => ['id' => 1, 'type' => 'private'], 'text' => $text]]);
            };
            $click = function (string $data) use ($bot, &$update): void {
                $bot->handle(['update_id' => ++$update, 'callback_query' => ['id' => (string) $update, 'from' => ['id' => 1], 'message' => ['chat' => ['id' => 1, 'type' => 'private']], 'data' => $data]]);
            };
            $say('/start');
            $click('language:' . $language);
            $click('reg:begin');
            $say(Messages::text($language, 'back'));
            self::assertSame('begin', $store->user(1)['step']);
            $say(Messages::text($language, 'back'));
            self::assertSame('begin', $store->user(1)['step']);
            $click('reg:begin');
            $say('Example Name');
            $say('Example Company');
            $say('+44 20 7946 0018');
            self::assertSame('country_confirm', $store->user(1)['step']);
            self::assertSame('', $store->user(1)['country']);
            $click('reg:country:yes');
            self::assertSame(PhoneNumber::country('+442079460018', $language), $store->user(1)['country']);
            self::assertSame('email', $store->user(1)['step']);
            $say('example@example.com');
            $say('/language');
            $say(Messages::text($language, 'back'));
            self::assertSame('review', $store->user(1)['step']);
            $click('language:' . $language);
            $click('reg:field:phone');
            $say('+77011234567');
            $click('reg:country:other');
            $say('Custom country');
            self::assertSame('Custom country', $store->user(1)['country']);
            self::assertSame('review', $store->user(1)['step']);

            $messages = [];
            $telegram = $this->createMock(TelegramGateway::class);
            $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text, array $options) use (&$messages): void {
                $messages[] = $options;
            });
            $translator = $this->createMock(Translator::class);
            $translator->expects(self::never())->method('translate');
            $worker = new Worker($store, $telegram, $translator);
            for ($i = 0; $i < 200 && $worker->tick(time() + $i); $i++) {}
            self::assertLessThan(200, $i);
            $inlineCount = 0;
            foreach ($messages as $index => $options) {
                if (isset($options['reply_markup']['inline_keyboard'])) {
                    $inlineCount++;
                    self::assertTrue($messages[$index - 1]['reply_markup']['remove_keyboard'] ?? false);
                }
            }
            self::assertGreaterThan(5, $inlineCount);
        }
    }
}
