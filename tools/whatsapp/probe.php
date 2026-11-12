#!/usr/bin/env php
<?php
declare(strict_types=1);

use Broadcast\Infrastructure\Runtime\ProcessLock;
use Broadcast\Infrastructure\WhatsApp\WppConnectClient;
use Broadcast\Local\LocalStand;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/LocalStand.php';
umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): never { throw new ErrorException('Probe operation failed.', 0, $severity); });
try {
    $root = dirname(__DIR__, 2);
    $directory = $root . '/var/whatsapp-app/live';
    if (!is_file($directory . '/application.sqlite')) { throw new RuntimeException('Initialize the local stand first.'); }
    $lock = ProcessLock::acquire($directory . '/runtime', 'worker');
    $token = json_decode(file_get_contents($root . '/secrets/whatsapp-spike/session-token.json'), true, flags: JSON_THROW_ON_ERROR);
    $allowed = json_decode(file_get_contents($root . '/secrets/whatsapp-app/allowed-recipients.json'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($allowed) || count($allowed) !== 1) { throw new RuntimeException('Probe requires exactly one allowed recipient.'); }
    $gateway = new WppConnectClient('http://127.0.0.1:21465', $token['session'], $token['token'], $allowed);
    $stand = new LocalStand($directory, $gateway, $allowed);
    $action = $argv[1] ?? 'inspect';
    $db = new PDO('sqlite:' . $directory . '/application.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (in_array($action, ['long', 'recovery', 'exchange'], true)) {
        $journal = $directory . '/probe-' . $action . '.json';
        $handle = fopen($journal, 'x');
        if ($handle === false) { throw new RuntimeException('Probe already queued; inspect it instead of resending.'); }
        fwrite($handle, json_encode(['state' => 'preparing', 'kind' => $action], JSON_THROW_ON_ERROR));
        fclose($handle);
        $identity = $stand->store->ensureIdentity('whatsapp', $allowed[0], '+' . $allowed[0]);
        $text = $action === 'long'
            ? "Локальный тест длинного текста WhatsApp.\n" . str_repeat("Привет! Hello! ¡Hola! Bonjour! 👋\n", 170) . 'Конец теста.'
            : ($action === 'recovery'
                ? 'Локальный тест восстановления очереди WhatsApp: сообщение после возвращения соединения.'
                : "Локальная проверка обмена после перезапуска.\nПривет! Hello! ¡Hola! Bonjour! 👋");
        $stand->store->transaction(fn () => $stand->store->queueUserReply($identity['user_id'], $text, [], $identity['id']));
        $id = (int) $db->query("SELECT MAX(id) FROM jobs WHERE channel='whatsapp'")->fetchColumn();
        file_put_contents($journal, json_encode(['state' => 'queued', 'kind' => $action, 'job_id' => $id,
            'codepoints' => mb_strlen($text), 'bytes' => strlen($text)], JSON_THROW_ON_ERROR));
        echo json_encode(['event' => 'probe_queued', 'job_id' => $id, 'kind' => $action, 'codepoints' => mb_strlen($text)], JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($action === 'tick') {
        $stand->worker->tick();
        echo "probe_worker_tick_complete\n";
    } elseif ($action === 'inspect') {
        $jobs = $db->query("SELECT id,kind,status,attempts,part,error FROM jobs WHERE channel='whatsapp' ORDER BY id")->fetchAll();
        $parts = $db->query('SELECT job_id,part,status,started_at FROM whatsapp_parts ORDER BY job_id,part')->fetchAll();
        echo json_encode(['jobs' => $jobs, 'parts' => $parts, 'channel' => $stand->store->channelState('whatsapp')], JSON_THROW_ON_ERROR) . PHP_EOL;
    } else { throw new InvalidArgumentException('Unknown probe action.'); }
} catch (Throwable $failure) {
    echo 'local_probe_failed; exception=' . $failure::class . PHP_EOL;
    exit(1);
}
