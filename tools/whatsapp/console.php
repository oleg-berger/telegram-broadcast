#!/usr/bin/env php
<?php
declare(strict_types=1);

use Broadcast\Infrastructure\Runtime\{ProcessLock, StopSignal};
use Broadcast\Infrastructure\WhatsApp\WppConnectClient;
use Broadcast\Local\LocalStand;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/LocalStand.php';

umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): never { throw new ErrorException('Local stand operation failed.', 0, $severity); });
$root = dirname(__DIR__, 2);
$command = $argv[1] ?? 'help';
try {
    if ($command === 'help') {
        echo "Local stand: init | demo | status | listen [--once] | staff TEXT | callback DATA | inbox\n";
        exit(0);
    }
    if ($command === 'demo') {
        $directory = $root . '/var/whatsapp-app/demos/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $stand = new LocalStand($directory);
        echo json_encode(['event' => 'local_demo_complete', 'checks' => $stand->scenario(), 'captures' => $stand->capture->counts()], JSON_THROW_ON_ERROR) . PHP_EOL;
        echo 'inbox=' . $directory . '/inbox.html' . PHP_EOL;
        exit(0);
    }
    if (!in_array($command, ['init', 'status', 'listen', 'staff', 'callback', 'inbox'], true)) { throw new InvalidArgumentException('Unknown local command.'); }
    $directory = $root . '/var/whatsapp-app/live';
    $runtime = $directory . '/runtime';
    $locks = [];
    if ($command === 'init') {
        LocalStand::initialize($directory);
        echo "local_stand_initialized\n";
        exit(0);
    }
    if (!is_file($directory . '/application.sqlite')) { throw new RuntimeException('Local stand is not initialized.'); }
    $settings = json_decode(file_get_contents($root . '/secrets/whatsapp-app/config.json'), true, flags: JSON_THROW_ON_ERROR);
    $token = json_decode(file_get_contents($root . '/secrets/whatsapp-spike/session-token.json'), true, flags: JSON_THROW_ON_ERROR);
    $allowed = json_decode(file_get_contents($root . '/secrets/whatsapp-app/allowed-recipients.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($settings['mode'] ?? null) !== 'local-fake-telegram-mail' || ($token['session'] ?? null) !== ($settings['session'] ?? null)
        || !is_array($allowed) || !array_is_list($allowed) || $allowed === []
        || ($settings['baseUrl'] ?? null) !== 'http://127.0.0.1:21465') {
        throw new InvalidArgumentException('Invalid local stand configuration.');
    }
    $gateway = new WppConnectClient($settings['baseUrl'], $settings['session'], $token['token'], $allowed);
    if ($command === 'listen') {
        $locks[] = ProcessLock::acquire($runtime, 'worker');
        $locks[] = ProcessLock::acquire($runtime, 'whatsapp-poll');
    } elseif (in_array($command, ['staff', 'callback'], true)) {
        $locks[] = ProcessLock::acquire($runtime, 'local-staff');
    }
    $stand = new LocalStand($directory, $gateway, $allowed);
    if ($command === 'status') {
        echo json_encode(['event' => 'local_stand_status', 'connected' => $gateway->connected(),
            'cursor' => $stand->store->channelCursor('whatsapp'), 'captures' => $stand->capture->counts()], JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($command === 'inbox') {
        echo 'inbox=' . $stand->inbox() . PHP_EOL;
    } elseif ($command === 'staff' || $command === 'callback') {
        if (!isset($argv[2]) || trim($argv[2]) === '') { throw new InvalidArgumentException('Staff command is missing.'); }
        $stand->telegram(99, $argv[2], $command === 'callback');
        echo "local_staff_action_queued\n";
    } else {
        $once = in_array('--once', $argv, true);
        $stop = new StopSignal();
        echo "local_listener_started; telegram=fake; mail=fake; translator=fake\n";
        do {
            try {
                $stand->pump();
                $stand->inbox();
            } catch (\Broadcast\Domain\WhatsAppFailure $failure) {
                echo 'local_transport_deferred; category=' . $failure->category . PHP_EOL;
                if ($once || $failure->category === 'permanent') { throw $failure; }
                $stop->pause(5);
            }
            if (!$once) { $stop->pause(1.1); }
        } while (!$once && !$stop->requested);
        echo "local_listener_stopped\n";
    }
} catch (Throwable $failure) {
    echo 'local_stand_failed; action=' . (in_array($command, ['init', 'demo', 'status', 'listen', 'staff', 'callback', 'inbox'], true) ? $command : 'unknown')
        . '; exception=' . $failure::class . PHP_EOL;
    exit(1);
}
