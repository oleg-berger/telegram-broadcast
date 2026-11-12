<?php
declare(strict_types=1);

namespace Broadcast\Local;

use Broadcast\Application\{Kernel, MailGateway, TelegramGateway, Translator, WhatsAppGateway, Worker};
use Broadcast\Infrastructure\Runtime\WhatsAppPoller;
use Broadcast\Infrastructure\Runtime\ProcessLock;
use Broadcast\Infrastructure\Runtime\SqliteBackup;
use Broadcast\Infrastructure\SqliteStore;

/** Private captured test messages are application artifacts, never console logs. */
final class Capture
{
    private \PDO $db;

    public function __construct(string $path)
    {
        $this->db = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY AUTOINCREMENT,channel TEXT,recipient TEXT,text TEXT,options TEXT)');
    }

    public function add(string $channel, string $recipient, string $text, array $options = []): int
    {
        $query = $this->db->prepare('INSERT INTO messages(channel,recipient,text,options) VALUES (?,?,?,?)');
        $query->execute([$channel, $recipient, $text, json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        return (int) $this->db->lastInsertId();
    }

    public function messages(?string $channel = null): array
    {
        $query = $this->db->prepare('SELECT * FROM messages WHERE (? IS NULL OR channel=?) ORDER BY id');
        $query->execute([$channel, $channel]);
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function counts(): array
    {
        return $this->db->query('SELECT channel,COUNT(*) AS total FROM messages GROUP BY channel')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}

final readonly class LocalTelegram implements TelegramGateway
{
    public function __construct(private Capture $capture) {}
    public function sendMessage(int $chatId, string $text, array $options = []): void { $this->capture->add('telegram', (string) $chatId, $text, $options); }
    public function sendPhoto(int $chatId, string $path, string $caption): void { $this->sendMessage($chatId, $caption); }
    public function getUpdates(int $offset, int $timeout = 25): array { return []; }
    public function answerCallbackQuery(string $id): void {}
    public function sendDocument(int $chatId, string $path, string $filename): void { $this->sendMessage($chatId, $filename); }
    public function setCommands(array $commands, array $scope = ['type' => 'default'], string $language = ''): void {}
}

final readonly class LocalMail implements MailGateway
{
    public function __construct(private Capture $capture) {}
    public function send(string $to, string $subject, string $text): void { $this->capture->add('email', $to, $text, ['subject' => $subject]); }
}

final readonly class LocalTranslator implements Translator
{
    public function translate(string $text, string $targetLanguage): string
    {
        // This explicit test marker makes the fake provider visible in the demonstration.
        return '[' . $targetLanguage . ' TEST] ' . $text;
    }
}

final class LocalWhatsApp implements WhatsAppGateway
{
    public bool $online = true;
    public ?string $failure = null;
    public function __construct(private Capture $capture) {}
    public function connected(): bool { return $this->online; }
    public function sendText(string $address, string $text): string
    {
        if ($this->failure !== null) { throw new \Broadcast\Domain\WhatsAppFailure($this->failure); }
        return 'local-' . $this->capture->add('whatsapp', $address, $text);
    }
    public function events(int $cursor, int $limit = 100): array { return []; }
    public function resolvePhone(string $address): ?string { return str_ends_with($address, '@lid') ? null : '+' . $address; }
}

final class LocalStand
{
    public static function initialize(string $directory): void
    {
        $locks = [];
        foreach (['worker', 'whatsapp-poll', 'local-staff'] as $role) {
            $locks[] = ProcessLock::acquire($directory . '/runtime', $role);
        }
        $path = $directory . '/application.sqlite';
        if (is_file($path)) { (new SqliteBackup())->create($path, $directory . '/backups/before-migrate'); }
        $store = new SqliteStore($path);
        try {
            $store->migrate();
            if (array_sum($store->inspectBusinessData()) === 0 && $store->channelCursor('whatsapp') === 0) {
                // Ignore old session history only while every local writer is locked out.
                $store->resetBusinessData(time());
            }
        } finally {
            $store->close();
        }
    }

    public readonly SqliteStore $store;
    public readonly Kernel $kernel;
    public readonly Capture $capture;
    public readonly Worker $worker;
    public readonly WhatsAppGateway $whatsapp;
    private WhatsAppPoller $poller;

    public function __construct(public readonly string $directory, ?WhatsAppGateway $whatsapp = null, ?array $allowedRecipients = null)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new \RuntimeException('Local stand directory unavailable.'); }
        $new = !is_file($directory . '/application.sqlite');
        $this->store = new SqliteStore($directory . '/application.sqlite');
        if ($new) { $this->store->migrate(); }
        $this->capture = new Capture($directory . '/capture.sqlite');
        $this->whatsapp = $whatsapp ?? new LocalWhatsApp($this->capture);
        $this->kernel = new Kernel($this->store, [98], [99]);
        $this->worker = new Worker($this->store, new LocalTelegram($this->capture), new LocalTranslator(),
            mail: new LocalMail($this->capture), whatsapp: $this->whatsapp, whatsappStaffIds: [99]);
        $this->poller = new WhatsAppPoller($this->store, $this->whatsapp, $this->kernel, $allowedRecipients);
    }

    public function telegram(int $actor, string $text, bool $callback = false): void
    {
        $id = $this->store->offset();
        $message = ['from' => ['id' => $actor], 'chat' => ['id' => $actor, 'type' => 'private'], 'date' => time(), 'text' => $text];
        $this->kernel->handle($callback
            ? ['update_id' => $id, 'callback_query' => ['id' => 'local-' . $id, 'from' => ['id' => $actor], 'message' => $message, 'data' => $text]]
            : ['update_id' => $id, 'message' => $message]);
    }

    public function incoming(string $address, string $text): void
    {
        if (!$this->whatsapp instanceof LocalWhatsApp) { throw new \LogicException('Synthetic input is restricted to offline demonstrations.'); }
        $sequence = $this->store->channelCursor('whatsapp') + 1;
        $this->kernel->handleWhatsApp(['event_id' => 'local-event-' . $sequence, 'sequence' => $sequence,
            'message_id' => 'local-incoming-' . $sequence, 'address' => $address, 'verified_phone' => '+' . $address,
            'body' => $text, 'from_me' => false, 'is_group' => false, 'created_at' => time()]);
    }

    public function pump(int $ticks = 1, bool $receive = true): void
    {
        try {
            if ($receive) { $this->poller->once(); }
        } finally {
            // Incoming transport failure must not starve Telegram, mail or queued work.
            for ($i = 0; $i < $ticks; ++$i) { $this->worker->tick(time() + ($this->whatsapp instanceof LocalWhatsApp ? $i * 3 : 0)); }
        }
    }

    public function inbox(): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><meta charset="utf-8"><title>Локальный стенд — сообщения</title>'
            . '<style>body{font:16px system-ui;max-width:1000px;margin:32px auto;background:#f5f5f5}article{background:white;padding:20px;margin:16px 0;border-radius:8px}pre{white-space:pre-wrap;overflow-wrap:anywhere}code{display:block;padding:8px;background:#eee}</style>'
            . '<h1>Локальные Telegram и email</h1><p>Это приватный журнал сообщений тестовых получателей. Внешние Telegram и почта не используются. Переводчик помечает результат TEST.</p>';
        foreach ($this->capture->messages() as $message) {
            $html .= '<article><strong>#' . $message['id'] . ' · ' . $escape($message['channel']) . ' · ' . $escape($message['recipient']) . '</strong><pre>' . $escape($message['text']) . '</pre>';
            $options = json_decode($message['options'], true, flags: JSON_THROW_ON_ERROR);
            foreach ($options['reply_markup']['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    $html .= '<p>' . $escape($button['text']) . '</p><code>.\tools\whatsapp\local.ps1 callback -Text &quot;' . $escape($button['callback_data'] ?? '') . '&quot;</code>';
                }
            }
            $html .= '</article>';
        }
        $path = $this->directory . '/inbox.html';
        file_put_contents($path, $html);
        return $path;
    }

    public function scenario(): array
    {
        if (!$this->whatsapp instanceof LocalWhatsApp) { throw new \LogicException('Scenario requires fake gateways.'); }
        $address = '442079460018';
        foreach (['/start', '2', '1', 'Local participant', 'Local company', '1', 'local@example.test', '1'] as $text) {
            $this->incoming($address, $text);
            $this->pump(20, false);
        }
        $person = $this->store->identity('whatsapp', $address)['user_id'];
        $user = $this->store->user($person);
        $this->require($user['status'] === 'pending', 'registration');
        $this->require(count($this->capture->messages('email')) === 0, 'approval_guard');
        $this->telegram(99, 'app:approve:' . $person . ':' . $user['revision'], true);
        $this->pump(20, false);
        $this->require($this->store->user($person)['status'] === 'approved', 'approval');
        $this->telegram(98, 'Local announcement: Привет! Hello! ¡Hola! Bonjour! 👋');
        $this->telegram(98, 'Local subject');
        $draft = $this->store->draft(1);
        $this->telegram(98, 'draft:submit:1:' . $draft['version'], true);
        $this->require(count($this->capture->messages('email')) === 0, 'broadcast_guard');
        $this->telegram(99, 'draft:approve:1:' . $this->store->draft(1)['version'], true);
        $this->pump(100, false);
        $mail = $this->capture->messages('email');
        $this->require(count($mail) === 1 && str_contains($mail[0]['text'], '[EN-GB TEST]'), 'independent_delivery');
        $reports = array_filter($this->capture->messages('telegram'), static fn ($row) => str_contains($row['text'], 'WhatsApp:'));
        $this->require(count($reports) >= 1, 'report');
        foreach (['/start', 'language:EN-GB', 'reg:begin', 'Same participant', 'Same company', '+' . $address, '/link'] as $i => $text) {
            $this->telegram(88, $text, in_array($i, [1, 2], true));
            $this->pump(20, false);
        }
        $confirmation = null;
        foreach ($this->capture->messages('whatsapp') as $message) {
            if (preg_match('~/confirm [A-Za-z0-9_-]{43}~', $message['text'], $match)) { $confirmation = $match[0]; }
        }
        $this->require($confirmation !== null, 'unique_confirmation');
        $this->incoming($address, $confirmation);
        $this->pump(100, false);
        $this->require($this->store->identity('telegram', '88')['user_id'] === $person, 'confirmed_link');
        $this->require(count($this->capture->messages('email')) === 1, 'email_no_duplicate');
        $catchup = array_filter($this->capture->messages('telegram'), static fn ($row) => $row['recipient'] === '88' && str_contains($row['text'], '[EN-GB TEST] Local announcement'));
        $this->require(count($catchup) === 1, 'new_channel_catchup');
        $this->inbox();
        return ['registration' => true, 'staff_approval' => true, 'broadcast_approval' => true, 'translated_delivery' => true,
            'report' => true, 'confirmed_link' => true, 'new_channel_only_catchup' => true, 'email_count' => 1];
    }

    private function require(bool $condition, string $stage): void
    {
        if (!$condition) { throw new \RuntimeException('Local demonstration failed: ' . $stage); }
    }
}
