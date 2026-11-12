<?php
declare(strict_types=1);

namespace Broadcast\Tests\Files;

use Broadcast\Application\AttachmentLinks;
use Broadcast\Domain\Attachment;
use Broadcast\Infrastructure\Files\AttachmentDownload;
use Broadcast\Infrastructure\Files\LocalAttachmentStorage;
use PHPUnit\Framework\TestCase;

final class AttachmentDownloadTest extends TestCase
{
    private string $directory;
    private LocalAttachmentStorage $storage;
    private Attachment $file;

    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/download-test-'.bin2hex(random_bytes(8));
        $this->storage=new LocalAttachmentStorage($this->directory);
        $source=$this->directory.'/source'; file_put_contents($source,'exact document bytes');
        $this->file=$this->storage->put($source,'Прайс café.txt'); unlink($source);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) { unlink($file); }
        rmdir($this->directory);
    }

    public function testGetStreamsBytesWithSafeHeadersAndHeadHasNoBody(): void
    {
        foreach (['GET','HEAD'] as $method) {
            [$status,$headers,$body]=$this->request($method,$this->file);
            self::assertSame(200,$status);
            self::assertSame($method==='GET'?'exact document bytes':'',$body);
            self::assertSame('20',$headers['Content-Length']);
            self::assertSame('nosniff',$headers['X-Content-Type-Options']);
            self::assertSame('no-store',$headers['Cache-Control']);
            self::assertStringContainsString("filename*=UTF-8''".rawurlencode('Прайс café.txt'),$headers['Content-Disposition']);
            self::assertArrayNotHasKey('ETag',$headers);
        }
    }

    public function testUnknownExpiredAndMissingBlobHaveIdenticalPublicResponse(): void
    {
        $unknown=$this->request('GET',null);
        $this->storage->delete($this->file->storageKey);
        self::assertSame($unknown,$this->request('GET',$this->file));
        self::assertSame(404,$unknown[0]);
        self::assertSame('',$unknown[2]);
    }

    public function testCorruptBlobNeverStreamsUnverifiedBytes(): void
    {
        file_put_contents($this->storage->path($this->file->storageKey),'different document!!');
        [$status,,$body]=$this->request('GET',$this->file);
        self::assertSame(404,$status); self::assertSame('',$body);
    }

    public function testOtherMethodsRejectedWithoutLookingUpToken(): void
    {
        $links=$this->createMock(AttachmentLinks::class); $links->expects(self::never())->method('resolve');
        $download=new AttachmentDownload($links,$this->storage);
        $status=$download->serve('POST','private-token',100,static function(int $status,array $headers):void {
            self::assertSame(405,$status); self::assertSame('GET, HEAD',$headers['Allow']);
        },static function(string $bytes):void { self::fail('Unexpected body.'); });
        self::assertSame(405,$status);
    }

    private function request(string $method,?Attachment $file): array
    {
        $links=$this->createMock(AttachmentLinks::class); $links->method('resolve')->willReturn($file);
        $result=[0,[],''];
        (new AttachmentDownload($links,$this->storage))->serve($method,'token',100,
            static function(int $status,array $headers) use (&$result):void {$result[0]=$status;$result[1]=$headers;},
            static function(string $bytes) use (&$result):void {$result[2].=$bytes;});
        return $result;
    }
}
