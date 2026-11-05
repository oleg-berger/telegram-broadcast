<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
use Broadcast\Application\Messages;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class DraftReworkTest extends TestCase
{
    private SqliteStore $store;
    private Kernel $bot;
    private int $update = 0;
    private array $logs = [];

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->bot = new Kernel($this->store, [98, 99], [100]);
    }

    private function say(int $id, string $text): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'message' => ['from' => ['id' => $id], 'chat' => ['id' => $id, 'type' => 'private'], 'text' => $text]]);
    }

    private function click(int $id, string $data): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'callback_query' => ['id' => (string) $this->update, 'from' => ['id' => $id], 'message' => ['chat' => ['id' => $id, 'type' => 'private']], 'data' => $data]]);
    }

    private function drain(int $recipient = 99): array
    {
        $messages = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text, array $options) use (&$messages, $recipient): void {
            if ($id === $recipient) { $messages[] = ['text' => $text, 'options' => $options]; }
        });
        $translator = $this->createMock(Translator::class);
        $translator->expects(self::never())->method('translate');
        $worker = new Worker($this->store, $telegram, $translator, function (array $record): void { $this->logs[] = $record; });
        $now = time();
        for ($i = 0; $i < 200 && $worker->tick($now + $i); ++$i) {}
        self::assertLessThan(200, $i);
        return $messages;
    }

    private function returned(string $text, string $subject, string $comment): array
    {
        $this->say(99, $text);
        $this->say(99, $subject);
        $this->click(99, 'draft:submit:1:' . $this->store->draft(1)['version']);
        $this->click(100, 'draft:return:1:' . $this->store->draft(1)['version']);
        $this->drain();
        $this->say(100, $comment);
        return $this->store->draft(1);
    }

    public function testReturnedNotificationHasOnlyCommentAndTwoVersionedEditButtons(): void
    {
        $draft = $this->returned('Private original announcement', 'Private subject', 'Please fix the date.');
        $messages = $this->drain();
        self::assertCount(1, $messages);
        self::assertSame(Messages::text('RU', 'draft_returned', ['comment' => 'Please fix the date.']), $messages[0]['text']);
        self::assertSame([
            [['text' => Messages::text('RU', 'draft_edit_text'), 'callback_data' => 'draft:text:1:' . $draft['version']]],
            [['text' => Messages::text('RU', 'draft_edit_subject'), 'callback_data' => 'draft:subject:1:' . $draft['version']]],
        ], $messages[0]['options']['reply_markup']['inline_keyboard']);
        self::assertSame('changes', $draft['status']);
        self::assertNull($this->store->broadcast(1));
        $this->bot->handle(['update_id' => $this->update, 'message' => ['from' => ['id' => 100], 'chat' => ['id' => 100, 'type' => 'private'], 'text' => 'Please fix the date.']]);
        self::assertSame([], $this->drain());
        $encoded = json_encode($this->logs, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private', $encoded);
        self::assertStringNotContainsString('Please fix', $encoded);
    }

    public function testEditingCopiesOnlyOriginalFieldAndRequiresResubmission(): void
    {
        $original = "Private <b>original</b> 😀\nSecond paragraph";
        $draft = $this->returned($original, 'Private subject', 'Correct it.');
        $this->drain();
        foreach (['text' => $original, 'subject' => 'Private subject'] as $field => $value) {
            $this->click(99, 'draft:' . $field . ':1:' . $draft['version']);
            $messages = $this->drain();
            self::assertCount(2, $messages);
            self::assertSame(Messages::text('RU', 'draft_edit_prompt'), $messages[0]['text']);
            self::assertSame([], $messages[0]['options']);
            self::assertSame($value, $messages[1]['text']);
            self::assertSame([['type' => 'pre', 'offset' => 0, 'length' => intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2)]], $messages[1]['options']['entities']);
            self::assertTrue($messages[1]['options']['reply_markup']['force_reply']);
            $this->say(99, 'Corrected ' . $field);
            $draft = $this->store->draft(1);
            self::assertSame('Corrected ' . $field, $draft[$field]);
            self::assertSame('changes', $draft['status']);
            self::assertNull($this->store->broadcast(1));
            $this->drain();
        }
        $this->click(99, 'draft:submit:1:' . $draft['version']);
        self::assertSame('pending', $this->store->draft(1)['status']);
        $this->click(100, 'draft:approve:1:' . $this->store->draft(1)['version']);
        self::assertSame('Corrected text', $this->store->broadcast(1)['text']);
        self::assertSame('Corrected subject', $this->store->broadcast(1)['subject']);
    }

    public function testLongTextHasNoTruncatedCopyButtonOrServiceHeaders(): void
    {
        $original = str_repeat("Абзац 😀 <tag>\n", 700);
        $draft = $this->returned(trim($original), 'Subject', 'Shorten it.');
        $this->drain();
        $this->click(99, 'draft:text:1:' . $draft['version']);
        $messages = $this->drain();
        self::assertSame([], $messages[0]['options']);
        $parts = array_slice($messages, 1);
        self::assertGreaterThan(1, count($parts));
        self::assertSame(trim($original), implode('', array_column($parts, 'text')));
        foreach ($parts as $index => $part) {
            self::assertSame(intdiv(strlen(mb_convert_encoding($part['text'], 'UTF-16LE', 'UTF-8')), 2), $part['options']['entities'][0]['length']);
            self::assertSame($index === count($parts) - 1, isset($part['options']['reply_markup']['force_reply']));
        }
    }

    public function testOtherAdminAndOldButtonsCannotOpenOrEditContent(): void
    {
        $draft = $this->returned('Private original', 'Subject', 'Correct it.');
        $this->drain();
        $this->click(98, 'draft:text:1:' . $draft['version']);
        self::assertNull($this->store->session(98));
        $messages = $this->drain(98);
        self::assertStringNotContainsString('Private original', json_encode($messages, JSON_THROW_ON_ERROR));
        $this->click(99, 'draft:text:1:' . ($draft['version'] - 1));
        self::assertNull($this->store->session(99));
        $this->drain();
        $this->click(99, 'draft:text:1:' . $draft['version']);
        $this->say(99, 'New original');
        $this->drain();
        $this->click(99, 'draft:subject:1:' . $draft['version']);
        self::assertNull($this->store->session(99));
        self::assertSame('New original', $this->store->draft(1)['text']);
    }
}
