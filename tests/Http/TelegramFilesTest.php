<?php
declare(strict_types=1);

namespace Broadcast\Tests\Http;

use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use Broadcast\Infrastructure\Http\TelegramClient;
use PHPUnit\Framework\TestCase;

final class TelegramFilesTest extends TestCase
{
    public function testGetFileIsSeparateFromDownload(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"ok":true,"result":{"file_path":"documents/file_1.pdf","file_size":4}}'));
        $client = new TelegramClient('private-token', $http);
        self::assertSame('documents/file_1.pdf', $client->filePath('file-id'));
        self::assertCount(1, $http->requests);
        self::assertSame(['file_id' => 'file-id'], json_decode($http->requests[0]['body'], true));
    }

    public function testUntrustedRemotePathNeverReachesDownloader(): void
    {
        $called = false;
        $client = new TelegramClient('private-token', new FakeHttpClient(), function () use (&$called): int { $called = true; return 200; });
        foreach (['../private', 'https://host/x', 'documents/../x', 'documents/x?token=x', 'documents/%2e%2e/x', '/documents/x'] as $path) {
            try { $client->downloadFile($path, sys_get_temp_dir() . '/unused-telegram'); self::fail('Unsafe path accepted.'); }
            catch (ApiFailure $e) { self::assertStringNotContainsString($path, $e->getMessage()); }
        }
        self::assertFalse($called);
    }

    public function testGetFileRejectsOversizedAndHostileMetadata(): void
    {
        foreach ([['file_path'=>'documents/a.pdf','file_size'=>20_000_001], ['file_path'=>'../private','file_size'=>1]] as $metadata) {
            $http = new FakeHttpClient();
            $http->respond(new HttpResponse(200, json_encode(['ok'=>true,'result'=>$metadata], JSON_THROW_ON_ERROR)));
            try { (new TelegramClient('private-token',$http))->filePath('id'); self::fail('Invalid metadata accepted.'); }
            catch (ApiFailure $failure) { self::assertFalse($failure->transient); self::assertStringNotContainsString('private-token',$failure->getMessage()); }
        }
    }

    public function testDownloadStreamsExactlyToLimitAndRefusesOverwrite(): void
    {
        $target = sys_get_temp_dir() . '/telegram-file-' . bin2hex(random_bytes(10));
        $client = new TelegramClient('private-token', new FakeHttpClient(), static function (string $url, callable $write): int {
            self::assertSame('https://api.telegram.org/file/botprivate-token/documents/a.txt', $url);
            self::assertSame(4, $write('abcd'));
            return 200;
        });
        try {
            $client->downloadFile('documents/a.txt', $target, 4);
            self::assertSame('abcd', file_get_contents($target));
            try { $client->downloadFile('documents/a.txt', $target, 4); self::fail('Existing target overwritten.'); }
            catch (ApiFailure) { self::assertSame('abcd', file_get_contents($target)); }
        } finally { if (is_file($target)) { unlink($target); } }
    }

    public function testOversizedOrFailedDownloadRemovesPartialFile(): void
    {
        foreach ([200, 404] as $status) {
            $target = sys_get_temp_dir() . '/telegram-file-' . bin2hex(random_bytes(10));
            $client = new TelegramClient('private-token', new FakeHttpClient(), static function (string $url, callable $write) use ($status): int {
                $write('abcd');
                if ($status === 200) { self::assertSame(0, $write('e')); }
                return $status;
            });
            try { $client->downloadFile('documents/a.txt', $target, 4); self::fail('Failed download accepted.'); }
            catch (ApiFailure $e) { self::assertFileDoesNotExist($target); self::assertStringNotContainsString('private-token', $e->getMessage()); }
        }
    }

    public function testAttachmentPreservesUnicodeFilenameMimeAndBytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tg-attachment');
        file_put_contents($path, '%PDF-content');
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"ok":true,"result":{"message_id":1}}'));
        $attachment = new Attachment(str_repeat('a', 64), 'Прайс café.pdf', 'application/pdf', 12, hash('sha256', '%PDF-content'));
        try { (new TelegramClient('private-token', $http))->sendAttachment(42, $attachment, $path); }
        finally { unlink($path); }
        self::assertStringContainsString('filename="Прайс café.pdf"', $http->requests[0]['body']);
        self::assertStringContainsString("Content-Type: application/pdf\r\n", $http->requests[0]['body']);
        self::assertStringContainsString('%PDF-content', $http->requests[0]['body']);
    }

    public function testChangedAttachmentNeverReachesNetwork(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tg-attachment'); file_put_contents($path, 'changed');
        $http = new FakeHttpClient();
        $attachment = new Attachment(str_repeat('a', 64), 'data.txt', 'text/plain', 4, hash('sha256', 'data'));
        try {
            try { (new TelegramClient('private-token', $http))->sendAttachment(42, $attachment, $path); self::fail('Changed file sent.'); }
            catch (ApiFailure) { self::assertSame([], $http->requests); }
        } finally { unlink($path); }
    }

    public function testLostDocumentAcknowledgementIsUncertainButConnectFailuresAreSafeToRetry(): void
    {
        foreach ([[null,true],[CURLE_OPERATION_TIMEDOUT,true],[CURLE_RECV_ERROR,true],[CURLE_COULDNT_CONNECT,false],
            [CURLE_COULDNT_RESOLVE_HOST,false],[CURLE_SSL_CONNECT_ERROR,false],[CURLE_SSL_CACERT,false]] as [$code,$uncertain]) {
            $http = new FakeHttpClient();
            $http->fail(new HttpTransportException('private diagnostic', $code===CURLE_OPERATION_TIMEDOUT, $code));
            $failure=$this->documentFailure($http);
            self::assertTrue($failure->transient);
            self::assertSame($uncertain,$failure->uncertain);
            self::assertStringNotContainsString('private',$failure->getMessage());
        }
    }

    public function testMalformedDocumentAcceptanceCannotBeRetriedBlindly(): void
    {
        foreach (['not-json','null','{"ok":true}','{"ok":true,"result":true}','{"ok":true,"result":{"message_id":0}}'] as $body) {
            $http = new FakeHttpClient(); $http->respond(new HttpResponse(200,$body));
            self::assertTrue($this->documentFailure($http)->uncertain);
        }
    }

    public function testExplicitDocumentRateLimitRetainsRetryAfter(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(429,'{"ok":false,"error_code":429,"parameters":{"retry_after":42}}'));
        $failure=$this->documentFailure($http);
        self::assertTrue($failure->transient); self::assertFalse($failure->uncertain); self::assertSame(42,$failure->retryAfter);
    }

    public function testTextDeliveryAlsoDistinguishesLostAcknowledgementFromExplicitRejection(): void
    {
        foreach ([new HttpTransportException('private data',true),new HttpResponse(200,'not-json'),new HttpResponse(200,'{"ok":true,"result":false}')] as $outcome) {
            $http = new FakeHttpClient();
            if ($outcome instanceof HttpTransportException) { $http->fail($outcome); } else { $http->respond($outcome); }
            try { (new TelegramClient('token',$http))->sendMessage(42,'text'); self::fail('Lost text acknowledgement accepted.'); }
            catch (ApiFailure $failure) { self::assertTrue($failure->uncertain); }
        }
    }

    private function documentFailure(FakeHttpClient $http): ApiFailure
    {
        $path=tempnam(sys_get_temp_dir(),'tg-ack'); file_put_contents($path,'data');
        try {
            (new TelegramClient('token',$http))->sendDocument(42,$path,'data.txt');
            self::fail('Expected document failure.');
        } catch (ApiFailure $failure) { return $failure; }
        finally { unlink($path); }
    }
}
