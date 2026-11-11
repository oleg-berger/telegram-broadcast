<?php
declare(strict_types=1);
namespace Broadcast\Tests;

use Broadcast\Domain\RecipientSnapshot;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class DirectoryLifecycleTest extends TestCase
{
    private function mailDelivery(): array
    {
        $store=new SqliteStore(':memory:');$store->migrate();
        $identity=$store->ensureIdentity('telegram','55');$user=$store->user($identity['user_id']);
        $user['language']='FR';$user['status']='approved';$user['subscribed']=1;$user['email']='one@example.test';$store->saveUser($user);
        $store->createDraft(99,'News');$store->saveDraft(1,1,['subject'=>'Subject','status'=>'pending']);$store->approveDraft(1,2,99);
        $store->saveTranslation(1,'FR','News');$store->saveTranslationSubject(1,'FR','Subject');
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);
        $db->exec("UPDATE jobs SET status='done' WHERE channel!='email'");
        $db->exec("UPDATE jobs SET payload=json_set(payload,'$.contact_id',7) WHERE kind='delivery' AND channel='email'");
        return [$store,$db];
    }

    public function testRetryRechecksManualDirectoryDisableBeforeEmailSend(): void
    {
        [$store,$db]=$this->mailDelivery();$allowed=true;$sends=0;$checks=0;
        $directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);
        $directory->method('deliveryAllowed')->willReturnCallback(function()use(&$allowed,&$checks){++$checks;return $allowed;});
        $mail=$this->createMock(\Broadcast\Application\MailGateway::class);
        $mail->method('send')->willReturnCallback(function()use(&$allowed,&$sends){++$sends;$allowed=false;throw new \Broadcast\Domain\ApiFailure('temporary',true);});
        $worker=new \Broadcast\Application\Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(\Broadcast\Application\Translator::class),mail:$mail,directory:$directory);
        $now=time();for($i=0;$i<30;++$i){$worker->tick($now+$i);}
        self::assertSame(1,$sends);self::assertSame(2,$checks);
        self::assertSame('skipped',$db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='email'")->fetchColumn());
    }

    public function testGmailRefreshConsumesTickAndRechecksDirectoryWithoutLoop(): void
    {
        [$store,$db]=$this->mailDelivery();$events=[];$ready=false;
        $directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);
        $directory->method('deliveryAllowed')->willReturnCallback(function()use(&$events){$events[]='check';return true;});
        $mail=$this->createMock(\Broadcast\Application\MailPreparationGateway::class);
        $mail->method('prepareDelivery')->willReturnCallback(function()use(&$ready,&$events){if($ready){return false;}$ready=true;$events[]='refresh';return true;});
        $mail->method('send')->willReturnCallback(function()use(&$events){$events[]='send';});
        $worker=new \Broadcast\Application\Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(\Broadcast\Application\Translator::class),mail:$mail,directory:$directory);
        $now=time();for($i=0;$i<20;++$i){$before=count($events);$worker->tick($now+$i);self::assertLessThanOrEqual(1,count($events)-$before);}
        self::assertSame(['check','refresh','check','send'],$events);
    }

    public function testDirectoryAuthorizationExpiresDuringLongQueuePause(): void
    {
        [$store,$db]=$this->mailDelivery();$allowed=true;
        $directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);
        $directory->expects(self::exactly(2))->method('deliveryAllowed')->willReturnCallback(function()use(&$allowed){return $allowed;});
        $mail=$this->createMock(\Broadcast\Application\MailGateway::class);$mail->expects(self::never())->method('send');
        $worker=new \Broadcast\Application\Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(\Broadcast\Application\Translator::class),mail:$mail,directory:$directory);
        $now=time();$worker->tick($now);$allowed=false;$worker->tick($now+61);
        self::assertSame('skipped',$db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='email'")->fetchColumn());
    }

    public function testWhatsAppHealthProbeRequiresFreshDirectoryAuthorization(): void
    {
        [$store,$db]=$this->mailDelivery();$allowed=true;$events=[];
        $db->exec("UPDATE jobs SET channel='whatsapp',payload=json_set(payload,'$.address','447700900001') WHERE kind='delivery' AND channel='email'");
        $directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);
        $directory->method('deliveryAllowed')->willReturnCallback(function()use(&$allowed,&$events){$events[]='check';return $allowed;});
        $wa=$this->createMock(\Broadcast\Application\WhatsAppGateway::class);
        $wa->method('connected')->willReturnCallback(function()use(&$allowed,&$events){$allowed=false;$events[]='health';return true;});
        $wa->expects(self::never())->method('sendText');
        $worker=new \Broadcast\Application\Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(\Broadcast\Application\Translator::class),whatsapp:$wa,directory:$directory);
        $now=time();for($i=0;$i<20;++$i){$before=count($events);$worker->tick($now+$i);self::assertLessThanOrEqual(1,count($events)-$before);}
        self::assertSame(['check','health','check'],$events);
        self::assertSame('skipped',$db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='whatsapp'")->fetchColumn());
    }

    public function testDirectoryCatchupSnapshotsTelegramAndEmailWithoutWhatsAppArchive(): void
    {
        $store=new SqliteStore(':memory:',directoryEnabled:true);$store->migrate();
        $store->createDraft(99,'News');$store->saveDraft(1,1,['subject'=>'Subject','status'=>'pending']);$store->approveDraft(1,2,99);
        $store->publishAudience(1,new RecipientSnapshot('original',time(),[]));$store->refreshBroadcasts();
        $identity=$store->ensureIdentity('telegram','55');$user=$store->user($identity['user_id']);
        $user['status']='approved';$user['subscribed']=1;$user['language']='FR';$user['phone']='+447700900001';$user['email']='one@example.test';$store->saveUser($user);
        self::assertTrue($store->addCatchup($user['id'],'FR'));
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);
        self::assertSame(0,(int)$db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery'")->fetchColumn());
        $db->exec("UPDATE jobs SET status='done' WHERE kind!='directory_catchup'");
        $directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);
        $directory->expects(self::once())->method('snapshot')->willReturn(new RecipientSnapshot('catchup',time(),[
            ['contact_id'=>7,'telegram_user_id'=>55,'phone'=>$user['phone'],'channel'=>'whatsapp','address'=>'447700900001','language'=>'ES'],
            ['contact_id'=>7,'telegram_user_id'=>55,'phone'=>$user['phone'],'channel'=>'telegram','address'=>'55','language'=>'FR'],
            ['contact_id'=>7,'telegram_user_id'=>55,'phone'=>$user['phone'],'channel'=>'email','address'=>'one@example.test','language'=>'FR'],
        ]));
        $worker=new \Broadcast\Application\Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(\Broadcast\Application\Translator::class),directory:$directory);
        $worker->tick(time());
        $rows=$db->query("SELECT channel,payload,language FROM jobs WHERE kind='delivery' ORDER BY channel")->fetchAll();
        self::assertSame(['email','telegram'],array_column($rows,'channel'));
        foreach($rows as $row){self::assertSame(7,json_decode($row['payload'],true)['contact_id']);self::assertSame('FR',$row['language']);}
        self::assertFalse($store->addChannelCatchup($user['id'],'FR','whatsapp'));
    }

    public function testCatchupDoesNotRepeatSameContactChannelAfterLocalIdentityChange(): void
    {
        [$store,$db]=$this->mailDelivery();$db->exec("UPDATE jobs SET status='done'");
        $identity=$store->ensureIdentity('telegram','66');$user=$store->user($identity['user_id']);$user['status']='approved';$user['phone']='+447700900001';$store->saveUser($user);
        $snapshot=new RecipientSnapshot('new-identity',time(),[
            ['contact_id'=>7,'telegram_user_id'=>66,'phone'=>'+447700900001','channel'=>'email','address'=>'new@example.test','language'=>'FR'],
            ['contact_id'=>7,'telegram_user_id'=>66,'phone'=>'+447700900001','channel'=>'telegram','address'=>'66','language'=>'FR'],
        ]);
        self::assertTrue($store->publishDirectoryCatchup(1,$user['id'],$snapshot));
        self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery' AND channel='email'")->fetchColumn());
        self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM jobs WHERE kind='delivery' AND channel='telegram' AND user_id=".$user['id'])->fetchColumn());
    }

    public function testCatchupUnsubscribeStopsAuthorizedEmailBeforeDirectorySync(): void
    {
        $store=new SqliteStore(':memory:',directoryEnabled:true);$store->migrate();
        $store->createDraft(99,'News');$store->saveDraft(1,1,['subject'=>'Subject','status'=>'pending']);$store->approveDraft(1,2,99);
        $store->publishAudience(1,new RecipientSnapshot('initial',time(),[]));$store->refreshBroadcasts();
        $identity=$store->ensureIdentity('telegram','55');$user=$store->user($identity['user_id']);$user['status']='approved';$user['phone']='+447700900001';$user['language']='FR';$user['email']='one@example.test';$store->saveUser($user);
        self::assertTrue($store->publishDirectoryCatchup(1,$user['id'],new RecipientSnapshot('catchup',time(),[
            ['contact_id'=>7,'telegram_user_id'=>55,'phone'=>$user['phone'],'channel'=>'email','address'=>'one@example.test','language'=>'FR'],
        ])));
        $store->saveTranslation(1,'FR','News');$store->saveTranslationSubject(1,'FR','Subject');
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);$db->exec("UPDATE jobs SET status='done' WHERE kind!='delivery'");
        $directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);$directory->method('deliveryAllowed')->willReturn(true);
        $mail=$this->createMock(\Broadcast\Application\MailGateway::class);$mail->expects(self::never())->method('send');
        $worker=new \Broadcast\Application\Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(\Broadcast\Application\Translator::class),mail:$mail,directory:$directory);
        $worker->tick(time());self::assertTrue($store->unsubscribeUser($user['id']));
        self::assertTrue($store->directoryBlocked(7));
        $worker->tick(time()+1);
        self::assertSame('skipped',$db->query("SELECT status FROM jobs WHERE kind='delivery' AND channel='email'")->fetchColumn());
    }

    public function testTransientDirectorySyncKeepsRetryingAndPermanentFailureRequiresSuperadmin(): void
    {
        $store=new SqliteStore(':memory:',directoryEnabled:true);$store->migrate();
        $identity=$store->ensureIdentity('telegram','55');$user=$store->user($identity['user_id']);$user['phone']='+447700900001';$store->saveUser($user);
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);
        $jobId=(int)$db->query("SELECT id FROM jobs WHERE kind='directory_sync'")->fetchColumn();
        $store->createDraft(99,'News');$store->saveDraft(1,1,['subject'=>'Subject','status'=>'pending']);$store->approveDraft(1,2,99);
        $directory=$this->createMock(\Broadcast\Application\ContactDirectory::class);$transient=true;$fail=true;
        $directory->method('applyOperation')->willReturnCallback(function()use(&$transient,&$fail){if($fail){throw new \Broadcast\Domain\ApiFailure('directory failed',$transient);}});
        $worker=new \Broadcast\Application\Worker($store,$this->createMock(\Broadcast\Application\TelegramGateway::class),$this->createMock(\Broadcast\Application\Translator::class),directory:$directory);
        $now=time();for($i=0;$i<8;++$i){$worker->tick($now+$i*4000);}
        self::assertSame('pending',$db->query("SELECT status FROM jobs WHERE id=$jobId")->fetchColumn());
        self::assertSame(8,(int)$db->query("SELECT attempts FROM jobs WHERE id=$jobId")->fetchColumn());
        $transient=false;$worker->tick($now+40000);
        self::assertSame('failed',$db->query("SELECT status FROM jobs WHERE id=$jobId")->fetchColumn());
        self::assertSame('preparing_audience',$store->broadcast(1)['status']);
        self::assertNull($store->nextJob($now+50000));
        self::assertFalse($store->retryAuxiliaryJob($jobId,$user['id'],false));
        self::assertTrue($store->retryAuxiliaryJob($jobId,99,true));
        $fail=false;$worker->tick($now+50000);
        self::assertSame('audience_snapshot',$store->nextJob($now+50001)['kind']);
    }

    public function testAllDirectoryChangesUseIncreasingRevisionAndBootstrapIsIdempotent(): void
    {
        $store=new SqliteStore(':memory:', directoryEnabled:true);$store->migrate();
        $identity=$store->ensureIdentity('telegram','55');
        $user=$store->user($identity['user_id']);$user['phone']='+447700900001';$user['email']='one@example.test';$user['status']='approved';$user['subscribed']=1;
        $store->saveUser($user);$store->bootstrapDirectory();$store->bootstrapDirectory();
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);
        self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM jobs WHERE kind='directory_sync'")->fetchColumn());
        $db->exec('INSERT INTO directory_links(contact_id,user_id) VALUES (7,'.$user['id'].')');
        self::assertTrue($store->unsubscribeUser($user['id']));
        $revisions=[];
        foreach($db->query("SELECT payload FROM jobs WHERE kind='directory_sync' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN) as $payload){$revisions[]=json_decode($payload,true)['change']['revision'];}
        self::assertSame(count($revisions),count(array_unique($revisions)));
        $sorted=$revisions;sort($sorted);self::assertSame($sorted,$revisions);
        self::assertTrue($store->directoryBlocked(7));
    }

    public function testApprovedTelegramLinksImportedPhoneBeforeNextBroadcastAndUnsubscribeBlocksBoth(): void
    {
        $store=new SqliteStore(':memory:',directoryEnabled:true);$store->migrate();
        $store->createDraft(99,'News');$store->saveDraft(1,1,['subject'=>'S','status'=>'pending']);$store->approveDraft(1,2,99);
        $store->publishAudience(1,new RecipientSnapshot('snap',time(),[['contact_id'=>7,'telegram_user_id'=>null,'phone'=>'+447700900001','channel'=>'whatsapp','address'=>'447700900001','language'=>'ES']]));
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);
        $messages=array_map(fn($payload)=>json_decode($payload,true)['text'],$db->query("SELECT payload FROM jobs WHERE kind='message'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertContains(\Broadcast\Application\Messages::text('RU','broadcast_audience_ready',['id'=>1,'telegram'=>0,'whatsapp'=>1,'email'=>0]),$messages);
        $old=$store->identity('whatsapp','447700900001')['user_id'];
        $tg=$store->ensureIdentity('telegram','55');$user=$store->user($tg['user_id']);
        $user['phone']='+447700900001';$user['email']='one@example.test';$user['status']='pending';$store->saveUser($user);
        self::assertTrue($store->decideApplication($user['id'],$store->user($user['id'])['revision'],'approved',99));
        self::assertNotSame($old,$user['id']);
        self::assertSame($user['id'],$store->identity('whatsapp','447700900001')['user_id']);
        self::assertTrue($store->unsubscribeUser($user['id']));self::assertTrue($store->directoryBlocked(7));
    }

    public function testDirectorySnapshotKeepsRegisteredAuthorExclusion(): void
    {
        $store=new SqliteStore(':memory:',directoryEnabled:true);$store->migrate();
        $store->createDraft(55,'News');$store->saveDraft(1,1,['subject'=>'Subject','status'=>'pending']);$store->approveDraft(1,2,99);
        self::assertTrue($store->publishAudience(1,new RecipientSnapshot('snapshot',time(),[
            ['contact_id'=>7,'telegram_user_id'=>55,'phone'=>'+447700900001','channel'=>'telegram','address'=>'55','language'=>'FR'],
            ['contact_id'=>7,'telegram_user_id'=>55,'phone'=>'+447700900001','channel'=>'email','address'=>'one@example.test','language'=>'FR'],
        ])));
        $db=(new \ReflectionProperty($store,'db'))->getValue($store);
        self::assertSame(0,(int)$db->query("SELECT COUNT(*) FROM jobs WHERE kind IN ('delivery','translate','translate_subject')")->fetchColumn());
    }
}
