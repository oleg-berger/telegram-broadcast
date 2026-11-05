<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use InvalidArgumentException;

final readonly class Config
{
    private const string ERROR = 'Invalid runtime configuration.';

    private function __construct(
        public string $telegramToken,
        public array $adminIds,
        public string $translatorApiKey,
        public string $translatorApiUrl,
        public string $databasePath,
        public string $backupDirectory,
        public string $runtimeDirectory,
        public array $superadminIds,
        public bool $adminsCanApproveUsers,
        public bool $adminsCanExportUsers,
        public array $smtp,
        public string $mailTransport,
        public array $gmail,
        public int $mailSendIntervalSeconds,
        public ?int $teamChannelId,
    ) {}

    /** @param array<string, mixed> $environment */
    public static function fromArray(array $environment): self
    {
        $token = self::required($environment, 'TELEGRAM_BOT_TOKEN');
        $adminIds = self::ids(self::required($environment, 'TELEGRAM_ADMIN_IDS'));
        $apiKey = self::required($environment, 'TRANSLATOR_API_KEY');
        $apiUrl = self::required($environment, 'TRANSLATOR_API_URL');
        $databasePath = self::required($environment, 'DATABASE_PATH');
        $superadminIds = self::ids(self::required($environment, 'TELEGRAM_SUPERADMIN_IDS'));
        try {
            new \Broadcast\Infrastructure\Http\DeepLTranslator($apiKey, $apiUrl);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(self::ERROR);
        }

        $appEnv = $environment['APP_ENV'] ?? 'production';
        if (!in_array($appEnv, ['production', 'test'], true)) { throw new InvalidArgumentException(self::ERROR); }
        $test = $appEnv === 'test';
        $transport = $environment['MAIL_TRANSPORT'] ?? 'smtp';
        if (!in_array($transport, ['smtp', 'gmail'], true)) { throw new InvalidArgumentException(self::ERROR); }
        $sender = [
            'fromAddress' => self::required($environment, 'MAIL_FROM_ADDRESS'),
            'fromName' => self::required($environment, 'MAIL_FROM_NAME'),
            'replyTo' => $environment['MAIL_REPLY_TO'] ?? '',
        ];
        if (!filter_var($sender['fromAddress'], FILTER_VALIDATE_EMAIL) || !is_string($sender['replyTo'])
            || ($sender['replyTo'] !== '' && !filter_var($sender['replyTo'], FILTER_VALIDATE_EMAIL)) || preg_match('/[\r\n]/', $sender['fromName'])) {
            throw new InvalidArgumentException(self::ERROR);
        }
        $smtp = $transport === 'smtp' ? [
            'host' => self::required($environment, 'SMTP_HOST'),
            'port' => self::integer($environment, 'SMTP_PORT', 1, 65535),
            'encryption' => self::required($environment, 'SMTP_ENCRYPTION'),
            'username' => $test ? ($environment['SMTP_USERNAME'] ?? '') : self::required($environment, 'SMTP_USERNAME'),
            'password' => $test ? ($environment['SMTP_PASSWORD'] ?? '') : self::required($environment, 'SMTP_PASSWORD'),
            ...$sender,
            'test' => $test,
        ] : [];
        if ($transport === 'smtp' && (
            !in_array($smtp['encryption'], $test ? ['starttls', 'tls', 'none'] : ['starttls', 'tls'], true)
            || !filter_var($smtp['fromAddress'], FILTER_VALIDATE_EMAIL)
            || ($smtp['replyTo'] !== '' && !filter_var($smtp['replyTo'], FILTER_VALIDATE_EMAIL))
            || preg_match('/[\r\n;]/', $smtp['host'])
            || preg_match('/[\r\n]/', $smtp['fromName'])
        )) {
            throw new InvalidArgumentException(self::ERROR);
        }
        $gmail = [];
        if ($transport === 'gmail') {
            try {
                $gmail = [
                    ...\Broadcast\Infrastructure\Mail\GoogleOAuthFiles::client(self::required($environment, 'GMAIL_OAUTH_CLIENT_FILE')),
                    'refreshToken' => \Broadcast\Infrastructure\Mail\GoogleOAuthFiles::refreshToken(self::required($environment, 'GMAIL_OAUTH_TOKEN_FILE')),
                    ...$sender,
                ];
            } catch (\Throwable) {
                throw new InvalidArgumentException(self::ERROR);
            }
        }
        $dataDirectory = dirname($databasePath);

        return new self(
            $token,
            $adminIds,
            $apiKey,
            $apiUrl,
            $databasePath,
            $dataDirectory . DIRECTORY_SEPARATOR . 'backups',
            $dataDirectory . DIRECTORY_SEPARATOR . 'runtime',
            $superadminIds,
            self::flag($environment, 'ADMINS_CAN_APPROVE_USERS'),
            self::flag($environment, 'ADMINS_CAN_EXPORT_USERS'),
            $smtp,
            $transport,
            $gmail,
            self::integer($environment, 'MAIL_SEND_INTERVAL_SECONDS', 1, 86400, 2),
            self::teamChannel($environment),
        );
    }

    public static function fromEnvironment(): self
    {
        $environment = [];
        foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_ADMIN_IDS', 'TELEGRAM_SUPERADMIN_IDS', 'TELEGRAM_TEAM_CHANNEL_ID', 'ADMINS_CAN_APPROVE_USERS', 'ADMINS_CAN_EXPORT_USERS', 'TRANSLATOR_API_KEY', 'TRANSLATOR_API_URL', 'DATABASE_PATH', 'APP_ENV', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_REPLY_TO', 'MAIL_SEND_INTERVAL_SECONDS', 'MAIL_TRANSPORT', 'GMAIL_OAUTH_CLIENT_FILE', 'GMAIL_OAUTH_TOKEN_FILE'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $environment[$key] = $value;
            }
        }

        return self::fromArray($environment);
    }

    /** @param array<string, mixed> $environment */
    private static function required(array $environment, string $key): string
    {
        $value = $environment[$key] ?? null;
        if (!is_string($value) || $value === '' || trim($value) !== $value || str_starts_with($value, 'replace_with_')) {
            throw new InvalidArgumentException(self::ERROR);
        }

        return $value;
    }

    /** @return list<int> */
    private static function ids(string $value): array
    {
        if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $value)) { throw new InvalidArgumentException(self::ERROR); }
        $ids = [];
        foreach (explode(',', $value) as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) { throw new InvalidArgumentException(self::ERROR); }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private static function flag(array $environment, string $key): bool
    {
        $value = $environment[$key] ?? 'false';
        if (!in_array($value, ['true', 'false'], true)) { throw new InvalidArgumentException(self::ERROR); }

        return $value === 'true';
    }

    private static function teamChannel(array $environment): ?int
    {
        $value = $environment['TELEGRAM_TEAM_CHANNEL_ID'] ?? '';
        if ($value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^-100[1-9][0-9]*$/D', $value)) {
            throw new InvalidArgumentException(self::ERROR);
        }
        return self::integer($environment, 'TELEGRAM_TEAM_CHANNEL_ID', -4503599627370495, -1001);
    }

    private static function integer(array $environment, string $key, int $min, int $max, ?int $default = null): int
    {
        $value = $environment[$key] ?? $default;
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($parsed === false) { throw new InvalidArgumentException(self::ERROR); }

        return $parsed;
    }
}
