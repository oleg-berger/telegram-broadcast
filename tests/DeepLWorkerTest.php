<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\MailGateway;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Worker;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\DeepLTranslator;
use Broadcast\Infrastructure\SqliteStore;
use Broadcast\Tests\Http\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeepLWorkerTest extends TestCase
{
    public static function translationScenarios(): iterable
    {
        foreach (['text', 'subject', 'comment'] as $kind) {
            yield $kind . ' recovers' => [$kind, true];
            yield $kind . ' exhausts retries' => [$kind, false];
        }
    }

    private function respond(FakeHttpClient $http, string $reason, string $text): void
    {
        $http->respond(new HttpResponse(200, json_encode([
            'translations' => $reason === 'stop' ? [['text' => $text]] : [],
        ], JSON_THROW_ON_ERROR)));
    }

    #[DataProvider('translationScenarios')]
    public function testIncompleteTranslationCannotBePersistedOrDelivered(string $kind, bool $recover): void
    {
        $store = new SqliteStore(':memory:');
        $store->migrate();
        $store->saveUser([
            'id' => 1, 'language' => 'FR', 'step' => 'review', 'name' => 'Test',
            'company' => 'Example', 'country' => 'France', 'phone' => '+447700900001',
            'email' => 'test@example.com', 'status' => 'approved', 'subscribed' => 1,
            'completed' => 1, 'choosing_language' => 0,
        ]);
        if ($kind === 'comment') {
            $store->queueComment(1, 'FR', 'private original', 'Prefix', 100);
        } else {
            $store->transaction(function () use ($store): void {
                $draft = $store->createDraft(99, 'private original');
                $store->saveDraft($draft['id'], $draft['version'], ['subject' => 'Original subject', 'status' => 'pending']);
                $store->approveDraft($draft['id'], $draft['version'] + 1, 100);
            });
        }

        $http = new FakeHttpClient();
        if ($kind === 'subject') {
            $this->respond($http, 'stop', 'Complete body');
        }
        $this->respond($http, 'length', 'private truncated translation');
        $translator = new DeepLTranslator('translator-secret', 'https://api-free.deepl.com', http: $http);
        $messages = [];
        $mails = [];
        $events = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text) use (&$messages): void {
            $messages[] = [$id, $text];
        });
        $mail = $this->createMock(MailGateway::class);
        $mail->method('send')->willReturnCallback(static function (string $to, string $subject, string $text) use (&$mails): void {
            $mails[] = [$to, $subject, $text];
        });
        $worker = new Worker($store, $telegram, $translator, static function (array $event) use (&$events): void {
            $events[] = $event;
        }, $mail);
        $now = time();
        if ($kind === 'subject') {
            self::assertTrue($worker->tick($now));
        }
        self::assertTrue($worker->tick($now));
        self::assertFalse($worker->tick($now + 1));
        self::assertSame([], $messages);
        self::assertSame([], $mails);
        self::assertSame($kind === 'subject' ? 'Complete body' : null, $store->translation(1, 'FR'));
        self::assertNull($store->translationSubject(1, 'FR'));
        $retry = $store->nextJob($now + 2);
        self::assertNotNull($retry);
        self::assertSame(1, $retry['attempts']);
        self::assertSame('temporary_api_error', $retry['error']);
        self::assertArrayNotHasKey('translated', $retry['payload']);

        if ($recover) {
            $this->respond($http, 'stop', $kind === 'subject' ? 'Complete subject' : 'Complete body');
            if ($kind === 'text') {
                $this->respond($http, 'stop', 'Complete subject');
            }
        } else {
            for ($i = 0; $i < 4; ++$i) {
                $this->respond($http, 'length', 'private truncated translation');
            }
        }
        for ($ticks = 0; $ticks < 30 && $worker->tick($now += 120); ++$ticks) {}
        self::assertLessThan(30, $ticks);
        $recipientMessages = array_values(array_filter($messages, static fn ($message) => $message[0] === 1));
        self::assertSame($recover ? [[1, $kind === 'comment' ? "Prefix\n\nComplete body" : 'Complete body']] : [], $recipientMessages);
        self::assertSame($recover && $kind !== 'comment' ? [['test@example.com', 'Complete subject', 'Complete body']] : [], $mails);
        self::assertCount(($recover ? 2 : 5) + ($kind === 'subject' || ($kind === 'text' && $recover) ? 1 : 0), $http->requests);
        if (!$recover) {
            self::assertSame($kind === 'subject' ? 'Complete body' : null, $store->translation(1, 'FR'));
            self::assertNull($store->translationSubject(1, 'FR'));
            self::assertCount(1, array_filter($messages, static fn ($message) => $message[0] === 100 && str_contains($message[1], $kind === 'comment' ? '/retryjob' : '/retry 1')));
            self::assertCount(1, array_filter($events, static fn ($event) => $event['event'] === 'job_failed' && $event['attempt'] === 5));
        }
        $output = json_encode([$messages, $mails, $events], JSON_THROW_ON_ERROR);
        foreach (['private truncated translation', 'private original', 'translator-secret'] as $privateText) {
            self::assertStringNotContainsString($privateText, $output);
        }
    }
}
