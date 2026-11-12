<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
umask(0077);
ini_set('display_errors','0');
ini_set('log_errors','0');
set_error_handler(static function (): never { throw new ErrorException('Local seed operation failed.'); });
try {
    if (getenv('APP_ENV')!=='test' || getenv('CONTACTS_MYSQL_DSN')!=='mysql:host=host.docker.internal;port=13306;dbname=broadcast_live_test;charset=utf8mb4') { throw new RuntimeException(); }
    $config=Broadcast\Infrastructure\Runtime\Config::fromEnvironment();
    $db=new PDO('sqlite:file:/data/application.sqlite?mode=ro');
    $rows=[];
    foreach ($db->query("SELECT phone,email FROM users WHERE status='approved' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $user) {
        if (!in_array(ltrim($user['phone'],'+'),$config->whatsapp['allowedRecipients'],true)) { continue; }
        $rows[$user['phone']]=['phone'=>$user['phone'],'email'=>$user['email'],'language'=>count($rows)%2===0?'ES':'EN-GB'];
    }
    if ($rows===[]) { throw new RuntimeException(); }
    $directory=Broadcast\Infrastructure\Runtime\MultichannelConfig::fromEnvironment()->directory();
    $directory->migrate();
    $directory->applyOperation('previously-approved-local-test-contacts-v1',['type'=>'import','rows'=>array_values($rows)]);
    echo 'LOCAL_TEST_CONTACTS_SEEDED count='.count($rows).' sends=0'.PHP_EOL;
} catch(Throwable $failure) { echo 'local_seed_failed; class='.$failure::class.PHP_EOL; exit(1); }
