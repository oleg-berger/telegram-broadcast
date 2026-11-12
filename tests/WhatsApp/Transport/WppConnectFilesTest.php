<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Transport;

use Broadcast\Domain\Attachment;
use Broadcast\Domain\WhatsAppFailure;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\WhatsApp\WhatsAppHttpClient;
use Broadcast\Infrastructure\WhatsApp\WhatsAppHttpFailure;
use Broadcast\Infrastructure\WhatsApp\WppConnectClient;
use PHPUnit\Framework\TestCase;

final class WppConnectFilesTest extends TestCase
{
    public function testPinnedServerFileContractUsesBase64AndAcceptanceId(): void
    {
        $http = new FileHttpFake(new HttpResponse(201, '{"status":"success","response":[{"id":"accepted-id","to":"77010000001@s.whatsapp.net","mimetype":"text/markdown","filename":"Прайс.md"}]}'));
        $path = tempnam(sys_get_temp_dir(), 'wa-file'); file_put_contents($path, '# Привет');
        $file = $this->attachment('# Привет');
        try { self::assertSame('accepted-id', $this->client($http)->sendFile('77010000001', $file, $path)); }
        finally { unlink($path); }
        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/api/test/send-file', $http->requests[0]['url']);
        self::assertSame(['phone'=>'77010000001','base64'=>base64_encode('# Привет'),'mimetype'=>'text/markdown','filename'=>'Прайс.md','isGroup'=>false], json_decode($http->requests[0]['body'], true));
    }

    public function testSixteenMiBLimitChoosesLinkBeforeAnySend(): void
    {
        $client = $this->client(new FileHttpFake(new HttpResponse(200, '{}')));
        self::assertTrue($client->supportsFile(new Attachment(str_repeat('a',64), 'a.pdf','application/pdf',16777216,str_repeat('a',64))));
        self::assertFalse($client->supportsFile(new Attachment(str_repeat('a',64), 'a.pdf','application/pdf',16777217,str_repeat('a',64))));
        self::assertFalse($client->supportsFile(new Attachment(str_repeat('a',64), 'empty.txt','text/plain',0,hash('sha256',''))));
    }

    public function testDisallowedRecipientAndChangedFileNeverReachTransport(): void
    {
        $http = new FileHttpFake(new HttpResponse(200, '{}'));
        $path = tempnam(sys_get_temp_dir(), 'wa-file'); file_put_contents($path, 'different');
        try {
            foreach (['77010000002','77010000001'] as $address) {
                try { $this->client($http)->sendFile($address,$this->attachment('expected'),$path); self::fail('Unsafe send.'); }
                catch (WhatsAppFailure $e) { self::assertSame('permanent',$e->category); }
            }
            self::assertSame([], $http->requests);
        } finally { unlink($path); }
    }

    public function testLostResponseAndMalformedAcceptanceAreUncertain(): void
    {
        foreach ([new WhatsAppHttpFailure(true),new HttpResponse(201,'{"status":"success","response":[{"id":"id","to":"wrong","mimetype":"text/markdown"}]}')] as $outcome) {
            $http = new FileHttpFake($outcome);
            $path = tempnam(sys_get_temp_dir(), 'wa-file'); file_put_contents($path, 'data');
            try {
                try { $this->client($http)->sendFile('77010000001',$this->attachment('data'),$path); self::fail('Uncertain send accepted.'); }
                catch (WhatsAppFailure $e) { self::assertSame('uncertain',$e->category); self::assertCount(1,$http->requests); }
            } finally { unlink($path); }
        }
    }

    private function attachment(string $data): Attachment { return new Attachment(str_repeat('a',64),'Прайс.md','text/markdown',strlen($data),hash('sha256',$data)); }
    private function client(FileHttpFake $http): WppConnectClient { return new WppConnectClient('http://127.0.0.1:21465','test','token',['77010000001'],$http); }
}

final class FileHttpFake implements WhatsAppHttpClient
{
    public array $requests=[];
    public function __construct(private HttpResponse|\Throwable $outcome) {}
    public function request(string $method,string $url,array $headers,?string $body,int $timeoutSeconds): HttpResponse
    {
        $this->requests[]=compact('method','url','headers','body','timeoutSeconds');
        if ($this->outcome instanceof \Throwable) { throw $this->outcome; }
        return $this->outcome;
    }
}
