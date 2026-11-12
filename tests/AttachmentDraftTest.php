<?php
declare(strict_types=1);
namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
use Broadcast\Application\AttachmentStorage;
use Broadcast\Application\AttachmentTelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\Attachment;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class AttachmentDraftTest extends TestCase
{
    public function testBroadcastAdminCanRetryOwnAttachmentButNotGeneralJobs(): void
    {
        $store=new SqliteStore(':memory:');$store->migrate();$store->createDraft(99,'News');$store->stageDraftAttachment(1,1,'file','report.pdf',3);
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);$job=(int)$db->query("SELECT id FROM jobs WHERE kind='attachment_download'")->fetchColumn();$db->exec("UPDATE jobs SET status='failed'");
        $bot=new Kernel($store,[99],[100]);$bot->handle(['update_id'=>1,'message'=>['from'=>['id'=>99],'chat'=>['id'=>99,'type'=>'private'],'text'=>'/retryjob '.$job]]);
        self::assertSame('pending',$db->query('SELECT status FROM jobs WHERE id='.$job)->fetchColumn());
        self::assertFalse($store->retryAuxiliaryJob($job,98,false,false));
        $store->queueExport(99);$export=(int)$db->query("SELECT id FROM jobs WHERE kind='export'")->fetchColumn();$db->exec("UPDATE jobs SET status='failed' WHERE kind='export'");
        self::assertFalse($store->retryAuxiliaryJob($export,99,false,false));
    }

    public function testDirectorySnapshotIsOnlyRequestedAfterApproval(): void
    {
        $store=new SqliteStore(':memory:',directoryEnabled:true);$store->migrate();$bot=new Kernel($store,[99],[100]);
        $bot->handle(['update_id'=>1,'message'=>['from'=>['id'=>100],'chat'=>['id'=>100,'type'=>'private'],'text'=>'News']]);
        $bot->handle(['update_id'=>2,'message'=>['from'=>['id'=>100],'chat'=>['id'=>100,'type'=>'private'],'text'=>'Subject']]);
        $events=[];$directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);
        $directory->expects(self::once())->method('snapshot')->willReturnCallback(function($id)use(&$events){$events[]='snapshot';return new \Broadcast\Domain\RecipientSnapshot($id,time(),[
            ['contact_id'=>1,'telegram_user_id'=>55,'phone'=>'+447700900001','channel'=>'telegram','address'=>'55','language'=>'FR'],
            ['contact_id'=>1,'telegram_user_id'=>55,'phone'=>'+447700900001','channel'=>'email','address'=>'one@example.test','language'=>'FR'],
            ['contact_id'=>1,'telegram_user_id'=>55,'phone'=>'+447700900001','channel'=>'whatsapp','address'=>'447700900001','language'=>'ES'],
            ['contact_id'=>2,'telegram_user_id'=>100,'phone'=>'+447700900002','channel'=>'whatsapp','address'=>'447700900002','language'=>'RU'],
        ]);});
        $telegram=$this->createMock(AttachmentTelegramGateway::class);$messages=[];
        $telegram->method('sendMessage')->willReturnCallback(function($id,$text,$options)use(&$events,&$messages){$events[]='send';$messages[]=[$text,$options];});
        $worker=new Worker($store,$telegram,$this->createMock(Translator::class),directory:$directory);
        $now=time();for($i=0;$i<20;++$i){$before=count($events);$worker->tick($now+$i);self::assertLessThanOrEqual(1,count($events)-$before);}
        $counts=\Broadcast\Application\Messages::text('RU','draft_audience_counts',['telegram'=>1,'email'=>1,'whatsapp'=>1]);
        self::assertStringNotContainsString($counts,implode("\n",array_column($messages,0)));
        self::assertNotContains('snapshot', $events);
        self::assertSame('draft:send:1:2',$messages[array_key_last($messages)][1]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertFalse($store->draftPreviewed(1,2,100));
        $bot->handle(['update_id'=>3,'callback_query'=>['id'=>'approve','from'=>['id'=>100],'message'=>['chat'=>['id'=>100,'type'=>'private']],'data'=>'draft:send:1:2']]);
        self::assertSame('preparing_audience',$store->broadcast(1)['status']);
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);
        $payloads=array_map(fn($payload)=>json_decode($payload,true),$db->query("SELECT payload FROM jobs WHERE kind='message' AND status='pending'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertNotContains(\Broadcast\Application\Messages::text('RU','broadcast_audience_preparing',['id'=>1]),array_column($payloads,'text'));
        for ($i = 0; $i < 5 && !in_array('snapshot', $events, true); ++$i) { $worker->tick($now + 30 + $i); }
        self::assertContains('snapshot', $events);
        $payloads = array_map(fn($payload) => json_decode($payload, true), $db->query("SELECT payload FROM jobs WHERE kind='message'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertContains(\Broadcast\Application\Messages::text('RU', 'broadcast_started', ['id' => 1, 'recipients' => 1]), array_column($payloads, 'text'));
    }

    public function testLegacyPreviewCountsApprovedEnabledChannelsAndExcludesAuthor(): void
    {
        $store=new SqliteStore(':memory:');$store->migrate();
        foreach([55,99] as $id){$identity=$store->ensureIdentity('telegram',(string)$id);$user=$store->user($identity['user_id']);$user['status']='approved';$user['email']='person'.$id.'@example.test';$user['subscribed']=1;$store->saveUser($user);}
        self::assertSame(['telegram'=>1,'whatsapp'=>0,'email'=>1],$store->draftAudienceCounts(99));
        $store->disableSubscriber($store->identity('telegram','55')['user_id']);
        self::assertSame(['telegram'=>0,'whatsapp'=>0,'email'=>1],$store->draftAudienceCounts(99));
    }

    public function testFileStartsDraftAndApprovalWaitsForDownloadAndPreview(): void
    {
        $store = new SqliteStore(':memory:'); $store->migrate();
        $bot = new Kernel($store, [99], [100]);
        $update = ['update_id' => 1, 'message' => ['from' => ['id' => 99], 'chat' => ['id' => 99, 'type' => 'private'], 'document' => ['file_id' => 'file1', 'file_name' => 'report.pdf', 'file_size' => 3]]];
        $bot->handle($update); $bot->handle($update);
        $draft = $store->draft(1);
        self::assertNotNull($draft);
        self::assertCount(1, $store->draftAttachments(1));
        $store->saveDraft(1, $draft['version'], ['subject' => 'Subject', 'status' => 'pending']);
        self::assertNull($store->approveDraft(1, $draft['version'] + 1, 100));
        $item = $store->draftAttachments(1)[0];
        $store->completeAttachment($item['id'], new Attachment(str_repeat('a', 64), 'report.pdf', 'application/pdf', 3, hash('sha256', 'pdf')));
        self::assertNull($store->approveDraft(1, $draft['version'] + 1, 100));
        $store->markDraftPreviewed(1, $draft['version'] + 1, 100);
        self::assertNotNull($store->approveDraft(1, $draft['version'] + 1, 100));
        self::assertCount(1, $store->broadcastAttachments(1));
    }

    public function testLimitsRemovalAndStaleVersionCannotChangeApprovedFiles(): void
    {
        $store = new SqliteStore(':memory:'); $store->migrate();
        $draft = $store->createDraft(99, 'News');
        self::assertFalse($store->stageDraftAttachment(1, 1, 'large', 'large.pdf', 20_000_001));
        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($store->stageDraftAttachment(1, $i + 1, 'f' . $i, 'report.pdf', 3));
        }
        self::assertFalse($store->stageDraftAttachment(1, 11, 'eleven', 'report.pdf', 3));
        self::assertFalse($store->removeDraftAttachment(1, 1, 1));
        self::assertTrue($store->removeDraftAttachment(1, 11, 1));
        self::assertCount(9, $store->draftAttachments(1));
        self::assertFalse($store->stageDraftAttachment(1, 11, 'stale', 'report.pdf', 3));
    }

    public function testEmptyAnnouncementCannotBeApprovedEvenWithSubject(): void
    {
        $store = new SqliteStore(':memory:'); $store->migrate();
        $store->createDraft(99, '');
        $store->saveDraft(1, 1, ['subject' => 'Subject', 'status' => 'pending']);
        self::assertNull($store->approveDraft(1, 2, 100));
    }

    public function testQueuedDownloadAndPreviewShowFilesBeforeApprovalButtons(): void
    {
        $store = new SqliteStore(':memory:'); $store->migrate();
        $bot = new Kernel($store,[99],[100]);$events=[];$operations=[];
        $telegram=$this->createMock(AttachmentTelegramGateway::class);
        $telegram->method('filePath')->willReturnCallback(function($id)use(&$operations){$operations[]='path';return 'documents/file';});
        $telegram->method('downloadFile')->willReturnCallback(function($remote,$target)use(&$operations){$operations[]='download';file_put_contents($target,'pdf');});
        $telegram->method('sendMessage')->willReturnCallback(function($id,$text,$options)use(&$events){$events[]=['actor'=>$id,'type'=>'text','options'=>$options];});
        $telegram->method('sendAttachment')->willReturnCallback(function($id,$file)use(&$events){$events[]=['actor'=>$id,'type'=>'file','name'=>$file->displayName];});
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('path')->willReturn('local');
        $storage->method('put')->willReturn(new Attachment(str_repeat('a',64),'report.pdf','application/pdf',3,hash('sha256','pdf')));
        $worker=new Worker($store,$telegram,$this->createMock(Translator::class),attachments:$storage);
        $bot->handle(['update_id'=>1,'message'=>['from'=>['id'=>99],'chat'=>['id'=>99,'type'=>'private'],'document'=>['file_id'=>'f','file_name'=>'report.pdf','file_size'=>3]]]);
        $bot->handle(['update_id'=>2,'message'=>['from'=>['id'=>99],'chat'=>['id'=>99,'type'=>'private'],'text'=>'Subject']]);
        $now=time();for($i=0;$i<40&&$worker->tick($now+$i);++$i){}
        self::assertSame(['path','download'],$operations);
        $bot->handle(['update_id'=>3,'message'=>['from'=>['id'=>99],'chat'=>['id'=>99,'type'=>'private'],'text'=>'/done']]);
        for($i=0;$i<40&&$worker->tick($now+100+$i);++$i){}
        $draft=$store->draft(1);
        $bot->handle(['update_id'=>4,'callback_query'=>['id'=>'c','from'=>['id'=>99],'message'=>['chat'=>['id'=>99,'type'=>'private']],'data'=>'draft:submit:1:'.$draft['version']]]);
        $events=[];for($i=0;$i<40&&$worker->tick($now+200+$i);++$i){}
        $super=array_values(array_filter($events,fn($e)=>$e['actor']===100));
        self::assertSame(['text','file','text'],array_column($super,'type'));
        self::assertArrayNotHasKey('reply_markup',$super[0]['options']);
        self::assertStringStartsWith('draft:approve:1:', $super[2]['options']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertNotNull($store->approveDraft(1,$store->draft(1)['version'],100));
    }

    public function testWorkerDownloadUsesExclusiveCreateTelegramClientContract(): void
    {
        $store=new SqliteStore(':memory:');$store->migrate();$store->createDraft(99,'');$store->stageDraftAttachment(1,1,'file','report.pdf',3);
        $http=$this->createMock(\Broadcast\Infrastructure\Http\HttpClient::class);
        $http->method('post')->willReturn(new \Broadcast\Infrastructure\Http\HttpResponse(200,'{"ok":true,"result":{"file_path":"documents/file.pdf","file_size":3}}'));
        $downloads=0;
        $telegram=new \Broadcast\Infrastructure\Http\TelegramClient('test-token',$http,static function($url,$write)use(&$downloads){++$downloads;$write('pdf');return 200;});
        $storage=$this->createMock(AttachmentStorage::class);$storage->method('put')->willReturnCallback(function($path,$name){self::assertSame('pdf',file_get_contents($path));return new Attachment(str_repeat('a',64),$name,'application/pdf',3,hash('sha256','pdf'));});
        $worker=new Worker($store,$telegram,$this->createMock(Translator::class),attachments:$storage);
        $now=time();for($i=0;$i<20&&$worker->tick($now+$i*10);++$i){}
        self::assertSame(1,$downloads);self::assertSame('ready',$store->attachment(1)['status']);
    }

    public function testWorkerDownloadsIntoPrivateStorageAndRemovesStagingFile(): void
    {
        $directory=sys_get_temp_dir().'/broadcast-stage-'.bin2hex(random_bytes(8));
        $storage=new \Broadcast\Infrastructure\Files\LocalAttachmentStorage($directory);
        $store=new SqliteStore(':memory:');$store->migrate();$store->createDraft(99,'');$store->stageDraftAttachment(1,1,'file','report.pdf',3);
        $telegram=$this->createMock(AttachmentTelegramGateway::class);$telegram->method('filePath')->willReturn('documents/file.pdf');$download=null;
        $telegram->method('downloadFile')->willReturnCallback(function($remote,$path)use(&$download,$directory){$download=$path;self::assertSame(realpath($directory),dirname($path));self::assertMatchesRegularExpression('/^\.[a-f0-9]{64}\.download$/D',basename($path));$handle=fopen($path,'xb');fwrite($handle,'pdf');fclose($handle);});
        $worker=new Worker($store,$telegram,$this->createMock(Translator::class),attachments:$storage);
        try {
            $now=time();for($i=0;$i<10;++$i){$worker->tick($now+$i);}
            self::assertNotNull($download);self::assertFileDoesNotExist($download);self::assertSame('ready',$store->attachment(1)['status']);
        } finally {
            foreach(new \DirectoryIterator($directory) as $file){if(!$file->isDot()){unlink($file->getPathname());}}rmdir($directory);
        }
    }
}
