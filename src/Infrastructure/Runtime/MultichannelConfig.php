<?php
declare(strict_types=1);
namespace Broadcast\Infrastructure\Runtime;

final readonly class MultichannelConfig
{
    private function __construct(public ?array $mysql, public ?string $attachmentPath, public ?string $publicBaseUrl, public int $mailMaxMessageBytes, public ?string $linkKeyFile) {}

    public static function fromEnvironment(): self
    {
        $values = [];
        foreach (['CONTACTS_MYSQL_DSN','CONTACTS_MYSQL_USER','CONTACTS_MYSQL_PASSWORD','ATTACHMENTS_PATH','ATTACHMENTS_PUBLIC_BASE_URL','MAIL_MAX_MESSAGE_BYTES','ATTACHMENTS_LINK_KEY_FILE'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') { $values[$key] = $value; }
        }
        return self::fromArray($values);
    }

    public static function fromArray(array $values): self
    {
        $mysql = null;
        if (array_intersect(array_keys($values), ['CONTACTS_MYSQL_DSN','CONTACTS_MYSQL_USER','CONTACTS_MYSQL_PASSWORD'])) {
            foreach (['CONTACTS_MYSQL_DSN','CONTACTS_MYSQL_USER','CONTACTS_MYSQL_PASSWORD'] as $key) {
                if (!is_string($values[$key] ?? null) || trim($values[$key]) === '' || str_starts_with($values[$key], 'replace_with_')) { throw new \InvalidArgumentException('Invalid multichannel configuration.'); }
            }
            if (!preg_match('/\Amysql:(?:host|unix_socket)=/', $values['CONTACTS_MYSQL_DSN']) || preg_match('/[\r\n\x00]/', $values['CONTACTS_MYSQL_DSN'])) { throw new \InvalidArgumentException('Invalid multichannel configuration.'); }
            $mysql = ['dsn'=>$values['CONTACTS_MYSQL_DSN'],'user'=>$values['CONTACTS_MYSQL_USER'],'password'=>$values['CONTACTS_MYSQL_PASSWORD']];
        }
        $path = $values['ATTACHMENTS_PATH'] ?? null;
        $url = $values['ATTACHMENTS_PUBLIC_BASE_URL'] ?? null;
        $keyFile = $values['ATTACHMENTS_LINK_KEY_FILE'] ?? null;
        if ($keyFile !== null && (!is_string($keyFile) || trim($keyFile) === '' || str_contains($keyFile, "\0"))) { throw new \InvalidArgumentException('Invalid multichannel configuration.'); }
        if (($path !== null && (!is_string($path) || trim($path) === '' || str_contains($path, "\0")))
            || ($url !== null && ($path === null || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null))) { throw new \InvalidArgumentException('Invalid multichannel configuration.'); }
        $limit = filter_var($values['MAIL_MAX_MESSAGE_BYTES'] ?? 25_000_000, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1024,'max_range'=>100_000_000]]);
        if ($limit === false) { throw new \InvalidArgumentException('Invalid multichannel configuration.'); }
        return new self($mysql, $path, $url, $limit, $keyFile);
    }

    public function directory(): ?\Broadcast\Infrastructure\Contacts\MySqlContactDirectory
    {
        if ($this->mysql === null) { return null; }
        try {
            $pdo = new \PDO($this->mysql['dsn'], $this->mysql['user'], $this->mysql['password'], [\PDO::ATTR_TIMEOUT=>5]);
            return new \Broadcast\Infrastructure\Contacts\MySqlContactDirectory($pdo);
        } catch (\Throwable) { throw new \RuntimeException('Contact directory connection failed.'); }
    }
}
