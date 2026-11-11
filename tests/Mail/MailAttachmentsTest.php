<?php
declare(strict_types=1);

namespace Broadcast\Tests\Mail;

use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;
use Broadcast\Domain\MailSizeExceeded;
use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Mail\GmailApiMailer;
use Broadcast\Infrastructure\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

final class MailAttachmentsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'mail-file-');
        file_put_contents($this->path, "%PDF-1.7\nexact test bytes\0\xff");
    }

    protected function tearDown(): void { unlink($this->path); }

    private function attachments(): array
    {
        return [['attachment' => new Attachment(str_repeat('a', 64), 'report.pdf', 'application/pdf', filesize($this->path), hash_file('sha256', $this->path)), 'path' => $this->path]];
    }

    private function assertMime(string $mime): void
    {
        self::assertStringContainsString('multipart/mixed', $mime);
        self::assertStringContainsString('Content-Type: application/pdf; name=report.pdf', $mime);
        self::assertStringContainsString('Content-Disposition: attachment; filename=report.pdf', $mime);
        self::assertStringContainsString(base64_encode(file_get_contents($this->path)), $mime);
        self::assertStringContainsString(base64_encode('Body'), $mime);
        self::assertStringNotContainsString($this->path, $mime);
    }

    public function testGmailDeliversExactMimeAttachment(): void
    {
        $http = new AttachmentGoogleHttp();
        $mailer = new GmailApiMailer('client', 'secret', 'refresh', 'from@example.com', 'Sender', '', $http);
        $mailer->sendWithAttachments('to@example.com', 'Subject', 'Body', $this->attachments());
        self::assertCount(2, $http->requests);
        $raw = json_decode($http->requests[1], true, flags: JSON_THROW_ON_ERROR)['raw'];
        $this->assertMime(base64_decode(strtr($raw, '-_', '+/'), true));
    }

    public function testFilesOnlyEmailRetainsSubjectAndAttachment(): void
    {
        $http = new AttachmentGoogleHttp();
        $mailer = new GmailApiMailer('client', 'secret', 'refresh', 'from@example.com', 'Sender', '', $http);
        $mailer->sendWithAttachments('to@example.com', 'Subject', '', $this->attachments());
        self::assertCount(2, $http->requests);
        $raw = json_decode($http->requests[1], true, flags: JSON_THROW_ON_ERROR)['raw'];
        $mime = base64_decode(strtr($raw, '-_', '+/'), true);
        self::assertStringContainsString('Subject: Subject', $mime);
        self::assertStringContainsString(base64_encode(file_get_contents($this->path)), $mime);
    }

    public function testSmtpDeliversExactMimeAttachmentToLocalReceiver(): void
    {
        $code = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($server, false), "\n"; flush();
        $client = stream_socket_accept($server, 10); if (!$client) { exit(1); }
        stream_set_timeout($client, 10); fwrite($client, "220 localhost ESMTP\r\n");
        $body = ''; $data = false;
        while (($line = fgets($client)) !== false) {
            if ($data) {
                if ($line === ".\r\n") { $data = false; fwrite($client, "250 accepted\r\n"); }
                else { $body .= str_starts_with($line, '..') ? substr($line, 1) : $line; }
            } elseif (str_starts_with($line, 'DATA')) { $data = true; fwrite($client, "354 continue\r\n"); }
            elseif (str_starts_with($line, 'QUIT')) { fwrite($client, "221 goodbye\r\n"); break; }
            else { fwrite($client, "250 OK\r\n"); }
        }
        fclose($client); fclose($server); echo base64_encode($body);
        PHP;
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $address = trim(fgets($pipes[1]));
            self::assertMatchesRegularExpression('/\A127\.0\.0\.1:[0-9]+\z/', $address);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            $mailer = new SmtpMailer('127.0.0.1', $port, 'none', '', '', 'from@example.com', 'Sender', '', true);
            $mailer->sendWithAttachments('to@example.com', 'Subject', 'Body', $this->attachments());
            $this->assertMime(base64_decode(stream_get_contents($pipes[1]), true));
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            fclose($pipes[1]); fclose($pipes[2]);
            proc_terminate($process); proc_close($process);
        }
    }

    public function testTamperedAttachmentFailsBeforeNetworkWithoutPathLeak(): void
    {
        $http = new AttachmentGoogleHttp();
        $files = $this->attachments();
        file_put_contents($this->path, 'tampered');
        $mailer = new GmailApiMailer('client', 'secret', 'refresh', 'from@example.com', 'Sender', '', $http);
        try {
            $mailer->sendWithAttachments('to@example.com', 'Subject', 'Body', $files);
            self::fail('Tampered file sent.');
        } catch (ApiFailure $failure) {
            self::assertSame([], $http->requests);
            self::assertStringNotContainsString($this->path, $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testSubjectInjectionFailsBeforeNetwork(): void
    {
        $http = new AttachmentGoogleHttp();
        $mailer = new GmailApiMailer('client', 'secret', 'refresh', 'from@example.com', 'Sender', '', $http);
        try {
            $mailer->sendWithAttachments('to@example.com', "Subject\r\nBcc: hidden@example.com", 'Body', $this->attachments());
            self::fail('Injected subject sent.');
        } catch (ApiFailure $failure) {
            self::assertSame([], $http->requests);
            self::assertStringNotContainsString('hidden@example.com', $failure->getMessage());
        }
    }

    public function testMimeExpansionExceedingBudgetFailsBeforeNetwork(): void
    {
        file_put_contents($this->path, str_repeat('x', 2000));
        $http = new AttachmentGoogleHttp();
        $mailer = new GmailApiMailer('client', 'secret', 'refresh', 'from@example.com', 'Sender', '', $http, null, 2500);
        try {
            $mailer->sendWithAttachments('to@example.com', 'Subject', 'Body', $this->attachments());
            self::fail('MIME overhead was not included.');
        } catch (MailSizeExceeded $failure) {
            self::assertSame('Email exceeds size limit.', $failure->getMessage());
            self::assertSame([], $http->requests);
        }
    }

    public function testSmtpSizeFailureRetainsTypedPreNetworkSignal(): void
    {
        $mailer=new SmtpMailer('127.0.0.1',1,'none','','','from@example.test','Sender','',true,1);
        $this->expectException(MailSizeExceeded::class);
        $mailer->sendWithAttachments('to@example.test','Subject','Body',$this->attachments());
    }

    public function testMemoryBudgetRejectsLargeMimeBeforeLoadingAttachments(): void
    {
        file_put_contents($this->path,str_repeat('x',20_000_000));
        $code=<<<'PHP'
        require $argv[1];
        $mail=new \PHPMailer\PHPMailer\PHPMailer(true);$mail->setFrom('from@example.test');$mail->addAddress('to@example.test');$mail->Subject='Subject';$mail->Body='Body';
        $file=new \Broadcast\Domain\Attachment(str_repeat('a',64),'large.pdf','application/pdf',filesize($argv[2]),hash_file('sha256',$argv[2]));
        try { \Broadcast\Infrastructure\Mail\MailAttachments::prepare($mail,array_fill(0,3,['attachment'=>$file,'path'=>$argv[2]]),100_000_000);echo 'UNSAFE'; }
        catch(\Broadcast\Domain\MailSizeExceeded){echo 'SIZE_REJECTED';}
        PHP;
        $process=proc_open([PHP_BINARY,'-d','memory_limit=128M','-r',$code,dirname(__DIR__,2).'/vendor/autoload.php',$this->path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        self::assertSame(0,$exit);self::assertSame('',$errors);self::assertSame('SIZE_REJECTED',$output);
    }

    public function testTenAttachmentsAreAllowedButElevenFailBeforeNetwork(): void
    {
        $http = new AttachmentGoogleHttp();
        $mailer = new GmailApiMailer('client', 'secret', 'refresh', 'from@example.com', 'Sender', '', $http);
        $files = [];
        for ($i = 1; $i <= 11; ++$i) {
            $files[] = ['attachment' => new Attachment(str_repeat('a', 64), 'file-' . $i . '.pdf', 'application/pdf', filesize($this->path), hash_file('sha256', $this->path)), 'path' => $this->path];
        }
        $mailer->sendWithAttachments('to@example.com', 'Subject', 'Body', array_slice($files, 0, 10));
        $raw = json_decode($http->requests[1], true, flags: JSON_THROW_ON_ERROR)['raw'];
        $mime = base64_decode(strtr($raw, '-_', '+/'), true);
        self::assertSame(10, substr_count($mime, 'Content-Disposition: attachment;'));
        try {
            $mailer->sendWithAttachments('to@example.com', 'Subject', 'Body', $files);
            self::fail('Eleven attachments sent.');
        } catch (ApiFailure $failure) {
            self::assertCount(2, $http->requests);
        }
    }
}

final class AttachmentGoogleHttp implements HttpClient
{
    public array $requests = [];
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = $body;
        return str_contains($url, 'oauth2')
            ? new HttpResponse(200, '{"access_token":"token","token_type":"Bearer","expires_in":3600}')
            : new HttpResponse(200, '{"id":"accepted"}');
    }
}
