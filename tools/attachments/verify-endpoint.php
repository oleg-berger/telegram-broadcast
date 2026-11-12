<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
umask(0077);
ini_set('display_errors','0');
ini_set('log_errors','0');
set_error_handler(static function (): never { throw new ErrorException('Endpoint verification failed.'); });

function record(int $type,string $content): string { return pack('CCnnCC',1,$type,1,strlen($content),0,0).$content; }
function bytes($stream,int $length): string {
    $value='';
    while (strlen($value)<$length) { $part=fread($stream,$length-strlen($value)); if ($part===false||$part==='') { throw new RuntimeException(); } $value.=$part; }
    return $value;
}
function request(string $method,string $token): string {
    $socket=stream_socket_client('tcp://127.0.0.1:9000',$errno,$error,5);
    if (!$socket) { throw new RuntimeException(); } stream_set_timeout($socket,5);
    $params='';
    foreach (['SCRIPT_FILENAME'=>'/app/public/download.php','REQUEST_METHOD'=>$method,'QUERY_STRING'=>'token='.$token,'SERVER_PROTOCOL'=>'HTTP/1.1','REQUEST_URI'=>'/download','GATEWAY_INTERFACE'=>'CGI/1.1','SERVER_NAME'=>'localhost','SERVER_PORT'=>'443'] as $name=>$value) {
        $params.=chr(strlen($name)).chr(strlen($value)).$name.$value;
    }
    $input=record(1,pack('nC',1,0).str_repeat("\0",5)).record(4,$params).record(4,'').record(5,'');
    if (fwrite($socket,$input)!==strlen($input)) { throw new RuntimeException(); }
    $output='';
    while (true) {
        $header=unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved',bytes($socket,8));
        $body=$header['length']>0?bytes($socket,$header['length']):'';
        if ($header['padding']>0) { bytes($socket,$header['padding']); }
        if ($header['type']===6) { $output.=$body; }
        if ($header['type']===3) { break; }
    }
    fclose($socket); return $output;
}
try {
    $action=$argv[1]??'';
    if ($action==='diagnose') {
        foreach (['ordinary'=>'sqlite:/data/application.sqlite','explicit_readonly'=>'sqlite:file:/data/application.sqlite?mode=ro'] as $label=>$dsn) {
            try { $probe=new PDO($dsn); $probe->query('SELECT COUNT(*) FROM attachment_links')->fetchColumn(); echo $label."_read=1\n"; }
            catch (PDOException $error) { echo $label.'_read=0; driver_code='.($error->errorInfo[1]??0)."\n"; }
        }
        exit(0);
    } elseif ($action==='prepare') {
        if (!is_file('/data/application.sqlite')) { (new Broadcast\Infrastructure\SqliteStore('/data/application.sqlite'))->migrate(); }
        $storage=new Broadcast\Infrastructure\Files\LocalAttachmentStorage(getenv('ATTACHMENTS_PATH') ?: '/data/attachments');
        file_put_contents('/data/endpoint-source.txt',"Exact local download bytes\n");
        $file=$storage->put('/data/endpoint-source.txt','Проверка.txt');
        $keyFile=getenv('ATTACHMENTS_LINK_KEY_FILE');
        $links=new Broadcast\Infrastructure\Files\SqliteAttachmentLinks('/data/application.sqlite',$storage,'https://service.imenka.world/download',$keyFile?:null);
        $url=$links->issue($file,1,time());
        file_put_contents('/data/endpoint-probe.json',json_encode(['token'=>basename($url),'sha256'=>$file->sha256],JSON_THROW_ON_ERROR));
        echo "ENDPOINT_FIXTURE_PREPARED\n";
    } elseif ($action==='verify') {
        $fixture=json_decode(file_get_contents('/data/endpoint-probe.json'),true,flags:JSON_THROW_ON_ERROR);
        $get=request('GET',$fixture['token']); [$headers,$body]=explode("\r\n\r\n",$get,2);
        if (!hash_equals($fixture['sha256'],hash('sha256',$body)) || !str_contains($headers,'X-Content-Type-Options: nosniff') || !str_contains($headers,'Cache-Control: no-store')) { echo json_encode(['get_404'=>str_contains($headers,'404'),'body_bytes'=>strlen($body),'nosniff'=>str_contains($headers,'nosniff')]),"\n"; throw new RuntimeException(); }
        $head=request('HEAD',$fixture['token']); [, $headBody]=explode("\r\n\r\n",$head,2);
        $unknown=request('GET',str_repeat('A',43));
        if ($headBody!=='' || !str_contains($unknown,'Status: 404')) { throw new RuntimeException(); }
        echo "FPM_ENDPOINT_VERIFIED exact_bytes=1 head=1 unknown_404=1\n";
    } else { throw new RuntimeException(); }
} catch(Throwable $failure) { echo 'endpoint_verification_failed; class='.$failure::class."\n"; exit(1); }
