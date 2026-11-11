<?php
declare(strict_types=1);
namespace Broadcast\Tests;

use Broadcast\Application\AttachmentStorage;
use Broadcast\Application\AttachmentTelegramGateway;
use Broadcast\Application\AttachmentMailGateway;
use Broadcast\Application\AttachmentLinks;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;
use Broadcast\Domain\MailSizeExceeded;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class AttachmentDeliveryTest extends TestCase
{
    private function telegramOnly(string $text='News'): array
    {
        [$store,$db]=$this->setupBroadcast($text);
        $store->saveTranslation(1,'ES',$text);$store->saveTranslationSubject(1,'ES','Subject');
        $db->exec("UPDATE jobs SET status='done' WHERE kind!='delivery' OR channel!='telegram'");
        return [$store,$db];
    }

    public function testRetentionStartsWithEachFileAttemptAfterText(): void
    {
        [$store,$db]=$this->telegramOnly();$sent=[];
        $tg=$this->createMock(AttachmentTelegramGateway::class);$tg->method('sendAttachment')->willReturnCallback(function($id,$file)use(&$sent){$sent[]=$file->displayName;});
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn('local');
        $worker=new Worker($store,$tg,$this->createMock(Translator::class),attachments:$storage);
        $now=time();$worker->tick($now);
        self::assertNull($store->attachment(1)['first_sent_at']);self::assertNull($store->attachment(2)['first_sent_at']);
        $worker->tick($now+100);
        self::assertSame($now+100,$store->attachment(1)['first_sent_at']);self::assertNull($store->attachment(2)['first_sent_at']);
        self::assertSame(['file1.pdf'],$sent);
    }

    public function testDirectFileExpirationIsRecheckedBeforeTransport(): void
    {
        [$store,$db]=$this->telegramOnly();$now=time();$store->markAttachmentSent(1,$now-2_592_000+5);
        $tg=$this->createMock(AttachmentTelegramGateway::class);$tg->expects(self::never())->method('sendAttachment');
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn('local');
        $worker=new Worker($store,$tg,$this->createMock(Translator::class),attachments:$storage);
        $worker->tick($now);$worker->tick($now+10);
        self::assertSame('failed',$db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='telegram'")->fetchColumn());
    }

    public function testFallbackLinkIsIssuedOnlyWhenItsPartIsAttempted(): void
    {
        [$store,$db]=$this->telegramOnly();$issues=0;
        $links=$this->createMock(AttachmentLinks::class);$links->method('issue')->willReturnCallback(function()use(&$issues){++$issues;return 'https://files.example.test/token';});
        $worker=new Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(Translator::class),attachments:$this->createMock(AttachmentStorage::class),attachmentLinks:$links);
        $now=time();$worker->tick($now);self::assertSame(0,$issues);self::assertNull($store->attachment(1)['first_sent_at']);
        $worker->tick($now+100);self::assertSame(1,$issues);self::assertSame($now+100,$store->attachment(1)['first_sent_at']);self::assertNull($store->attachment(2)['first_sent_at']);
    }

    private function setupBroadcast(string $text): array
    {
        $store = new SqliteStore(':memory:'); $store->migrate();
        $store->saveUser(['id'=>1,'language'=>'ES','step'=>'review','name'=>'N','company'=>'C','country'=>'ES','phone'=>'+34600000001','email'=>'one@example.test','status'=>'approved','subscribed'=>1,'completed'=>1,'choosing_language'=>0]);
        $store->createDraft(99, $text);
        foreach ([1,2] as $n) {
            $store->stageDraftAttachment(1, $n, 'file'.$n, 'file'.$n.'.pdf', 3);
            $store->completeAttachment($n, new Attachment(str_repeat((string)$n,64), 'file'.$n.'.pdf','application/pdf',3,hash('sha256','pdf')));
        }
        $store->saveDraft(1,3,['subject'=>'Subject','status'=>'pending']);
        $store->markDraftPreviewed(1,4,100);
        $store->approveDraft(1,4,100);
        return [$store, (new \ReflectionProperty($store,'db'))->getValue($store)];
    }

    public function testRetryContinuesAtFailedFileAndEmailGetsOneCompleteMessage(): void
    {
        [$store,$db]=$this->setupBroadcast('News');
        $sent=[]; $failed=false; $mailSent=[];
        $tg=$this->createMock(AttachmentTelegramGateway::class);
        $tg->method('sendMessage')->willReturnCallback(function($id,$text)use(&$sent){if($id===1){$sent[]=$text;}});
        $tg->method('sendAttachment')->willReturnCallback(function($id,$file,$path)use(&$sent,&$failed){if($file->displayName==='file2.pdf'&&!$failed){$failed=true;throw new ApiFailure('transient',true);} $sent[]=$file->displayName;});
        $translator=$this->createMock(Translator::class);$translator->method('translate')->willReturnCallback(fn($text,$language)=>$language.':'.$text);
        $mail=$this->createMock(AttachmentMailGateway::class);$mail->method('sendWithAttachments')->willReturnCallback(function($to,$subject,$text,$files)use(&$mailSent){$mailSent[]=count($files);});
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn('safe-test-path');
        $worker=new Worker($store,$tg,$translator,mail:$mail,attachments:$storage);
        $now=time();for($i=0;$i<100&&$worker->tick($now+$i*10);++$i){}
        self::assertSame(['ES:News','file1.pdf','file2.pdf'],$sent);
        self::assertSame([2],$mailSent);
        self::assertSame(['done','done'],$db->query("SELECT status FROM jobs WHERE kind='delivery' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testFilesOnlySkipsBodyTranslationAndUnsubscribeStopsRemainingFiles(): void
    {
        [$store,$db]=$this->setupBroadcast('');$sent=[];$translated=[];
        $tg=$this->createMock(AttachmentTelegramGateway::class);
        $tg->method('sendAttachment')->willReturnCallback(function($id,$file)use(&$sent,$store){$sent[]=$file->displayName;$store->unsubscribeUser(1);});
        $translator=$this->createMock(Translator::class);$translator->method('translate')->willReturnCallback(function($text,$language)use(&$translated){$translated[]=$text;return $language.':'.$text;});
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn('safe-test-path');
        $worker=new Worker($store,$tg,$translator,attachments:$storage);
        $now=time();for($i=0;$i<100&&$worker->tick($now+$i*10);++$i){}
        self::assertSame(['Subject'],$translated);
        self::assertSame(['file1.pdf'],$sent);
        self::assertSame(['skipped','skipped'],$db->query("SELECT status FROM jobs WHERE kind='delivery' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testCleanupDeletesExpiredBlobsAndStopsPendingDeliveries(): void
    {
        [$store,$db]=$this->setupBroadcast('News');$deleted=[];
        $now=time();$store->markAttachmentSent(1,$now-2_592_000);$store->markAttachmentSent(2,$now-2_592_000);
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('delete')->willReturnCallback(function($key)use(&$deleted){$deleted[]=$key;});
        $tg=$this->createMock(AttachmentTelegramGateway::class);$tg->expects(self::never())->method('sendAttachment');
        $translator=$this->createMock(Translator::class);$translator->method('translate')->willReturnCallback(fn($text)=>$text);
        $worker=new Worker($store,$tg,$translator,attachments:$storage);
        for($i=0;$i<100&&$worker->tick($now+$i*10);++$i){}
        self::assertSame([str_repeat('1',64),str_repeat('2',64)],$deleted);
        self::assertSame(['expired','expired'],$db->query('SELECT status FROM attachments ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(['failed','failed'],$db->query("SELECT status FROM jobs WHERE kind='delivery' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertNull($store->approveDraft(1,4,100));
    }



    public function testOversizeEmailUsesStableLinksInOneMessageAcrossRetry(): void
    {
        [$store,$db]=$this->setupBroadcast('News');$sent=[];$attempt=0;
        $tg=$this->createMock(AttachmentTelegramGateway::class);
        $translator=$this->createMock(Translator::class);$translator->method('translate')->willReturnCallback(fn($text)=>$text);
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn('local');
        $links=$this->createMock(AttachmentLinks::class);$links->expects(self::exactly(4))->method('issue')->willReturnCallback(fn($file)=>'https://files.example.test/'.$file->storageKey);
        $mail=$this->createMock(AttachmentMailGateway::class);$mail->expects(self::once())->method('sendWithAttachments')->willThrowException(new MailSizeExceeded());
        $mail->method('send')->willReturnCallback(function($to,$subject,$body)use(&$sent,&$attempt){$sent[]=$body;if(++$attempt===1){throw new ApiFailure('temporary',true);}});
        $worker=new Worker($store,$tg,$translator,mail:$mail,attachments:$storage,attachmentLinks:$links,mailMaxMessageBytes:10000);
        $now=time();for($i=0;$i<100&&$worker->tick($now+$i*10);++$i){}
        self::assertCount(2,$sent);self::assertSame($sent[0],$sent[1]);
        self::assertStringContainsString('https://files.example.test/'.str_repeat('1',64),$sent[1]);
        self::assertStringContainsString('https://files.example.test/'.str_repeat('2',64),$sent[1]);
        self::assertSame('done',$db->query("SELECT status FROM jobs WHERE channel='email' AND kind='delivery'")->fetchColumn());
        self::assertSame(1,(int)$db->query("SELECT attempts FROM jobs WHERE channel='email' AND kind='delivery'")->fetchColumn());
        self::assertStringNotContainsString('https://',$db->query("SELECT payload FROM jobs WHERE channel='email' AND kind='delivery'")->fetchColumn());
    }

    public function testRestartAfterTelegramSendIntentKeepsUnknownWithoutDuplicate(): void
    {
        [$store,$db]=$this->setupBroadcast('News');
        $tg=$this->createMock(AttachmentTelegramGateway::class);
        $tg->expects(self::once())->method('sendAttachment')->willThrowException(new \RuntimeException('simulated process crash'));
        $translator=$this->createMock(Translator::class);$translator->method('translate')->willReturnCallback(fn($text)=>$text);
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn('local');
        $worker=new Worker($store,$tg,$translator,attachments:$storage);
        $now=time();$crashed=false;
        for($i=0;$i<100;++$i){try{$worker->tick($now+$i);}catch(\RuntimeException){$crashed=true;break;}}
        self::assertTrue($crashed);
        self::assertSame('sending',$db->query("SELECT status FROM jobs WHERE channel='telegram' AND kind='delivery'")->fetchColumn());
        $worker=new Worker($store,$tg,$translator,attachments:$storage);
        for($i=0;$i<100&&$worker->tick($now+100+$i);++$i){}
        self::assertSame('unknown',$db->query("SELECT status FROM jobs WHERE channel='telegram' AND kind='delivery'")->fetchColumn());
        $store->retryBroadcast(1);
        self::assertSame('unknown',$db->query("SELECT status FROM jobs WHERE channel='telegram' AND kind='delivery'")->fetchColumn());
    }

    public function testActualGmailMimeOverflowFallsBackBeforeAnyMessageRequest(): void
    {
        [$store,$db]=$this->setupBroadcast('News');$path=tempnam(sys_get_temp_dir(),'mail-mime-');file_put_contents($path,str_repeat('p',2000));
        foreach([1,2] as $id){$file=new Attachment(str_repeat((string)$id,64),'file'.$id.'.pdf','application/pdf',2000,hash_file('sha256',$path));$q=$db->prepare('UPDATE attachments SET metadata=? WHERE id=?');$q->execute([json_encode($file),$id]);}
        $http=$this->createMock(\Broadcast\Infrastructure\Http\HttpClient::class);$requests=[];$mime='';
        $http->method('post')->willReturnCallback(function($url,$headers,$body)use(&$requests,&$mime){$requests[]=$url;if(str_contains($url,'oauth2')){return new \Broadcast\Infrastructure\Http\HttpResponse(200,'{"access_token":"test","token_type":"Bearer","expires_in":3600}');}$mime=base64_decode(strtr(json_decode($body,true)['raw'],'-_','+/'));return new \Broadcast\Infrastructure\Http\HttpResponse(200,'{"id":"accepted"}');});
        $mailer=new \Broadcast\Infrastructure\Mail\GmailApiMailer('client','secret','refresh','sender@example.test','Sender',http:$http,maxMessageBytes:2500);
        $translator=$this->createMock(Translator::class);$translator->method('translate')->willReturnCallback(fn($text)=>$text);
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn($path);
        $links=$this->createMock(AttachmentLinks::class);$links->expects(self::exactly(2))->method('issue')->willReturnCallback(fn($file)=>'https://files.example.test/'.$file->storageKey);
        $worker=new Worker($store,$this->createMock(AttachmentTelegramGateway::class),$translator,mail:$mailer,attachments:$storage,attachmentLinks:$links);
        try{$now=time();for($i=0;$i<80;++$i){$before=count($requests);$worker->tick($now+$i);self::assertLessThanOrEqual(1,count($requests)-$before);}}
        finally{unlink($path);}
        self::assertCount(2,$requests);self::assertStringNotContainsString('multipart/mixed',$mime);
        $sections=preg_split('/\r?\n\r?\n/',$mime,2);
        self::assertStringContainsString('https://files.example.test/'.str_repeat('1',64),base64_decode($sections[1]));
        self::assertSame('done',$db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='email'")->fetchColumn());
        self::assertSame(0,(int)$db->query("SELECT attempts FROM jobs WHERE kind='delivery' AND channel='email'")->fetchColumn());
    }
}
