<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Transport;

use Broadcast\Infrastructure\WhatsApp\CurlWhatsAppHttpClient;
use Broadcast\Infrastructure\WhatsApp\WhatsAppHttpFailure;
use PHPUnit\Framework\TestCase;

final class CurlWhatsAppFilesTest extends TestCase
{
    public function testConnectionFailureClassificationUsesAvailableCurlConstants(): void
    {
        $script = <<<'PHP'
namespace Broadcast\Infrastructure\WhatsApp {
    function curl_exec($handle) { return false; }
    function curl_errno($handle) { return CURLE_COULDNT_CONNECT; }
}
namespace {
    require getcwd().'/vendor/autoload.php';
    try {
        (new \Broadcast\Infrastructure\WhatsApp\CurlWhatsAppHttpClient())->request('POST','http://127.0.0.1:1/api/test/send-file',[],'{}',1);
        exit(2);
    } catch (\Broadcast\Infrastructure\WhatsApp\WhatsAppHttpFailure $failure) {
        if ($failure->requestMayHaveBeenSent) { exit(3); }
        echo 'safe pre-send failure';
    }
}
PHP;
        $process=proc_open([PHP_BINARY,'-r',$script],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__,3));
        self::assertIsResource($process);
        fclose($pipes[0]); $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$errors);
        self::assertSame('safe pre-send failure',$output);
    }

    public function testMediaCanExceedTextLimitAgainstLocalReceiver(): void
    {
        $server = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) { exit(2); }
echo stream_socket_get_name($server, false), "\n"; flush();
$peer = stream_socket_accept($server, 5);
if (!$peer) { exit(3); }
stream_set_timeout($peer, 5);
$length = 0;
while (($line = fgets($peer)) !== false && trim($line) !== '') {
    if (preg_match('/^Content-Length: ([0-9]+)/i', $line, $match)) { $length = (int) $match[1]; }
}
$received = 0;
while ($received < $length && !feof($peer)) {
    $chunk = fread($peer, min(8192, $length - $received));
    if ($chunk === '' || $chunk === false) { break; }
    $received += strlen($chunk);
}
$body = json_encode(['received' => $received]);
fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
fclose($peer); fclose($server);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $server], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 10);
            $address = trim((string) fgets($pipes[1]));
            self::assertMatchesRegularExpression('/\A127\.0\.0\.1:[0-9]+\z/', $address);
            $response = (new CurlWhatsAppHttpClient())->request('POST', 'http://' . $address . '/api/test/send-file', ['Content-Type' => 'application/json'], str_repeat('a', 200_000), 5);
            self::assertSame(200, $response->status);
            self::assertSame(['received'=>200_000], json_decode($response->body, true));
        } finally {
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testLargeNonMediaAndOversizedMediaAreRejectedBeforeNetwork(): void
    {
        foreach ([['send-message', 131_073], ['send-file', 22_435_161]] as [$endpoint,$size]) {
            try {
                (new CurlWhatsAppHttpClient())->request('POST', 'http://127.0.0.1:1/api/test/' . $endpoint, [], str_repeat('a', $size), 5);
                self::fail('Oversized request was accepted.');
            } catch (WhatsAppHttpFailure $failure) {
                self::assertFalse($failure->requestMayHaveBeenSent);
            }
        }
    }
}
