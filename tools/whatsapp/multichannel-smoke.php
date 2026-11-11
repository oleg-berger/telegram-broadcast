#!/usr/bin/env php
<?php
declare(strict_types=1);

use Broadcast\Application\{AttachmentTelegramGateway, Translator, Worker};
use Broadcast\Domain\Attachment;
use Broadcast\Infrastructure\Contacts\MySqlContactDirectory;
use Broadcast\Infrastructure\Files\LocalAttachmentStorage;
use Broadcast\Infrastructure\Http\TelegramClient;
use Broadcast\Infrastructure\Mail\GmailApiMailer;
use Broadcast\Infrastructure\Runtime\{Config, ProcessLock};
use Broadcast\Infrastructure\SqliteStore;
use Broadcast\Infrastructure\WhatsApp\WppConnectClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new ErrorException('Local smoke operation failed.'); });

/** The smoke test may contact only an already approved test chat; synthetic staff notifications stay local. */
final readonly class SmokeTelegram implements AttachmentTelegramGateway
{
    public function __construct(private TelegramClient $client, private int $operator) {}
    public function sendMessage(int $chatId, string $text, array $options = []): void { if ($chatId === $this->operator) { $this->client->sendMessage($chatId, $text, $options); } }
    public function sendAttachment(int $chatId, Attachment $attachment, string $path): void { if ($chatId !== $this->operator) { throw new RuntimeException('Unexpected smoke recipient.'); } $this->client->sendAttachment($chatId, $attachment, $path); }
    public function sendPhoto(int $chatId, string $path, string $caption): void { throw new RuntimeException('Unexpected smoke action.'); }
    public function sendDocument(int $chatId, string $path, string $filename): void { throw new RuntimeException('Unexpected smoke action.'); }
    public function answerCallbackQuery(string $id): void {}
    public function getUpdates(int $offset, int $timeout = 25): array { return []; }
    public function setCommands(array $commands, array $scope = ['type' => 'default'], string $language = ''): void {}
    public function filePath(string $fileId): string { throw new RuntimeException('Unexpected smoke download.'); }
    public function downloadFile(string $remotePath, string $targetPath, int $maxBytes = 20_000_000): void { throw new RuntimeException('Unexpected smoke download.'); }
}

try {
    if (getenv('APP_ENV') !== 'test' || getenv('DATABASE_PATH') !== '/smoke/application.sqlite'
        || getenv('CONTACTS_MYSQL_DSN') !== 'mysql:host=host.docker.internal;port=13306;dbname=broadcast_smoke_test;charset=utf8mb4') {
        throw new RuntimeException('Isolated smoke configuration required.');
    }
    $config = Config::fromEnvironment();
    $allowed = $config->whatsapp['allowedRecipients'] ?? [];
    if ($allowed === []) { throw new RuntimeException('Existing test allowlist required.'); }
    $action = $argv[1] ?? 'inspect';
    $source = new PDO('sqlite:file:/seed/application.sqlite?mode=ro', options: [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $recipient = null;
    foreach ($source->query("SELECT * FROM users WHERE status='approved' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array(ltrim($row['phone'], '+'), $allowed, true) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) { $recipient = $row; break; }
    }
    if ($recipient === null) { throw new RuntimeException('Previously approved allowlisted test contact required.'); }
    $approvedTelegram = $source->query("SELECT u.*,i.address AS telegram_address FROM channel_identities i JOIN users u ON u.id=i.user_id WHERE i.channel='telegram' AND i.retired=0 AND i.enabled=1 AND u.status='approved' AND u.subscribed=1 ORDER BY u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$approvedTelegram) { throw new RuntimeException('Previously approved test Telegram chat required.'); }
    $operator = (int) $approvedTelegram['telegram_address'];
    if ($action === 'inspect') {
        echo "smoke_isolated; approved_whatsapp=1; approved_email=1; approved_telegram=1\n";
        exit(0);
    }
    if (!in_array($action, ['prepare', 'prepare-files-only', 'prepare-telegram', 'tick', 'run', 'report'], true)) { throw new RuntimeException('Unknown smoke action.'); }
    $lock = ProcessLock::acquire('/smoke/runtime', 'smoke');
    $directory = new MySqlContactDirectory(new PDO(getenv('CONTACTS_MYSQL_DSN'), getenv('CONTACTS_MYSQL_USER'), getenv('CONTACTS_MYSQL_PASSWORD')));
    $storage = new LocalAttachmentStorage('/smoke/attachments');
    if ($action === 'prepare-telegram') {
        $marker=fopen('/smoke/prepared-telegram','x'); if ($marker===false) { throw new RuntimeException('Telegram scenario already prepared.'); } fclose($marker);
        $catalogue=new PDO(getenv('CONTACTS_MYSQL_DSN'),getenv('CONTACTS_MYSQL_USER'),getenv('CONTACTS_MYSQL_PASSWORD'));
        $catalogue->exec('UPDATE bc_contacts SET active=0');
        $store=new SqliteStore('/smoke/application.sqlite',directoryEnabled:true);
        $identity=$store->ensureIdentity('telegram',(string)$operator);
        $user=$store->user($identity['user_id']);
        $store->saveUser(array_replace($user,['phone'=>$approvedTelegram['phone'],'email'=>'','language'=>$approvedTelegram['language'],'status'=>'approved','completed'=>1,'subscribed'=>1,'choosing_language'=>0]));
        $draft=$store->createDraft(99,'');
        $bytes="Local Telegram document verification; previously approved test chat.\n";
        file_put_contents('/smoke/telegram-document.txt',$bytes);
        $file=$storage->put('/smoke/telegram-document.txt','telegram-document.txt');
        if (!$store->stageDraftAttachment($draft['id'],$draft['version'],'local-telegram-document','telegram-document.txt',strlen($bytes))) { throw new RuntimeException(); }
        $files=$store->draftAttachments($draft['id']);$store->completeAttachment(end($files)['id'],$file);$draft=$store->draft($draft['id']);
        $store->saveDraft($draft['id'],$draft['version'],['subject'=>'LOCAL TEST TELEGRAM: original document','status'=>'pending']);$draft=$store->draft($draft['id']);
        $store->markDraftPreviewed($draft['id'],$draft['version'],99);
        if ($store->approveDraft($draft['id'],$draft['version'],99)===null) { throw new RuntimeException(); }
        echo "telegram_smoke_prepared; files=1; sends=0\n";exit(0);
    }
    if (in_array($action, ['prepare','prepare-files-only'], true)) {
        $filesOnly = $action === 'prepare-files-only';
        // Refuse to recreate accepted/uncertain deliveries. Use an explicit new isolated volume for another run.
        $marker = fopen('/smoke/' . ($filesOnly ? 'prepared-files-only' : 'prepared'), 'x');
        if ($marker === false) { throw new RuntimeException('Smoke already prepared.'); }
        fclose($marker);
        $directory->migrate();
        $directory->applyOperation('smoke-approved-contact-v1', ['type'=>'import','rows'=>[['phone'=>$recipient['phone'],'language'=>'ES','email'=>$recipient['email']]]]);
        $store = new SqliteStore('/smoke/application.sqlite', directoryEnabled: true);
        $store->migrate();
        $identity = $store->ensureIdentity('telegram', (string) $operator);
        $user = $store->user($identity['user_id']);
        $store->saveUser(array_replace($user, ['phone'=>$recipient['phone'],'email'=>$recipient['email'],'language'=>'FR','name'=>'Local smoke','company'=>'Local smoke','status'=>'approved','completed'=>1,'subscribed'=>1,'choosing_language'=>0]));
        $draft = $store->createDraft(99, $filesOnly ? '' : 'Local attachment test. Ten files; original bytes are preserved.');
        $samples = ['sample.txt'=>"Local file test\n",'sample.md'=>"# Local file test\n",'sample.csv'=>"name,value\ntest,1\n",'sample.json'=>"{\"test\":true}\n",'sample.xml'=>"<?xml version=\"1.0\"?><test>true</test>"];
        $samples['sample.png'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhU0AAAAASUVORK5CYII=', true);
        $samples['sample.pdf'] = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        foreach (['sample.docx'=>['[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>','_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>','word/document.xml'=>'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Local file test</w:t></w:r></w:p></w:body></w:document>'], 'sample.zip'=>['sample.txt'=>"Local file test\n"]] as $name=>$entries) {
            $path='/smoke/'.$name; $zip=new ZipArchive(); $zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);
            foreach ($entries as $entry=>$bytes) { $zip->addFromString($entry,$bytes); } $zip->close(); $samples[$name]=file_get_contents($path);
        }
        $writer=new OpenSpout\Writer\XLSX\Writer(); $writer->openToFile('/smoke/sample.xlsx'); $writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['Local file test',1])); $writer->close(); $samples['sample.xlsx']=file_get_contents('/smoke/sample.xlsx');
        if ($filesOnly) { $samples=['local-files-only.txt'=>"Separate files-only local scenario; original delivery is not retried.\n"]; }
        foreach ($samples as $name=>$bytes) {
            $path='/smoke/'.$name; file_put_contents($path,$bytes); $file=$storage->put($path,$name);
            if (!$store->stageDraftAttachment($draft['id'],$draft['version'],'local-'.$name,$name,strlen($bytes))) { throw new RuntimeException('Smoke attachment staging failed.'); }
            $files=$store->draftAttachments($draft['id']); $store->completeAttachment(end($files)['id'],$file); $draft=$store->draft($draft['id']);
        }
        $store->saveDraft($draft['id'],$draft['version'],['subject'=>$filesOnly?'LOCAL TEST FILES ONLY: one unchanged file':'LOCAL TEST: ten unchanged files','status'=>'pending']); $draft=$store->draft($draft['id']);
        $store->markDraftPreviewed($draft['id'],$draft['version'],99);
        if ($store->approveDraft($draft['id'],$draft['version'],99) === null) { throw new RuntimeException('Smoke approval failed.'); }
        if ($filesOnly) {
            // The completed WhatsApp scenario is not repeated in the independent Telegram/email scenario.
            $catalogue=new PDO(getenv('CONTACTS_MYSQL_DSN'),getenv('CONTACTS_MYSQL_USER'),getenv('CONTACTS_MYSQL_PASSWORD'));
            $catalogue->exec('UPDATE bc_contacts SET whatsapp_enabled=0');
        }
        echo 'smoke_prepared; files='.count($samples)."; contacts=1; sends=0\n";
        exit(0);
    }
    $store = new SqliteStore('/smoke/application.sqlite', directoryEnabled: true);
    if (in_array($action, ['tick', 'run'], true)) {
        $translator = new class implements Translator { public function translate(string $text,string $targetLanguage): string { return '['.$targetLanguage.' LOCAL TEST] '.$text; } };
        $worker = new Worker($store,new SmokeTelegram(new TelegramClient($config->telegramToken),$operator),$translator,
            mail:new GmailApiMailer(...$config->gmail),whatsapp:new WppConnectClient(...$config->whatsapp),directory:$directory,attachments:$storage);
        for ($ticks=0; $ticks<($action === 'run' ? 150 : 1); ++$ticks) {
            if (!$worker->tick()) { break; }
            if ($action === 'run') { usleep(250_000); }
        }
        echo "smoke_tick_complete\n";
    }
    $db=new PDO('sqlite:/smoke/application.sqlite');
    echo json_encode(['jobs'=>$db->query('SELECT kind,channel,status,COUNT(*) AS total FROM jobs GROUP BY kind,channel,status')->fetchAll(PDO::FETCH_ASSOC),
        'delivery_progress'=>$db->query("SELECT id,channel,status,attempts,part,error FROM jobs WHERE kind='delivery'")->fetchAll(PDO::FETCH_ASSOC),
        'parts'=>$db->query('SELECT status,COUNT(*) AS total FROM whatsapp_parts GROUP BY status')->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR),"\n";
} catch (Throwable $failure) {
    echo 'smoke_failed; class='.$failure::class."\n";
    exit(1);
}
