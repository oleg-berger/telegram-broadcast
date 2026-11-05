<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Mail;

/** Only allowlisted diagnostics may leave the local authorization command. */
final class GmailAuthorizationFailure extends \RuntimeException
{
    public function __construct(private readonly string $reason, private readonly ?int $status = null)
    {
        parent::__construct('Gmail authorization failed.');
    }

    public function diagnostic(): string
    {
        return match ($this->reason) {
            'timeout' => 'Тайм-аут соединения PHP с oauth2.googleapis.com. Проверьте сеть, VPN, прокси и правила доступа для PHP.',
            'dns' => 'PHP не смог разрешить адрес сервера Google или прокси. Проверьте DNS и настройки прокси.',
            'connection' => 'PHP не смог подключиться к oauth2.googleapis.com или прокси. Проверьте сеть, VPN и межсетевой экран.',
            'tls' => 'PHP не смог проверить TLS-сертификат Google. Проверьте доверенные CA и curl.cainfo в php.ini; проверку TLS не отключайте.',
            'transport' => 'Ошибка HTTPS-запроса PHP к oauth2.googleapis.com. Проверьте сеть и настройку PHP cURL.',
            'invalid_grant' => 'Google отклонил код авторизации: он мог истечь или уже использоваться. Запустите команду заново и откройте новую ссылку.',
            'invalid_client' => 'Google отклонил OAuth-клиент. Скачайте актуальный Desktop JSON из нужного проекта.',
            'scope' => 'Google не подтвердил разрешение gmail.send. Повторите авторизацию и разрешите отправку писем.',
            'refresh_token' => 'Google не выдал refresh token. Повторите согласие; при необходимости отзовите прежний доступ приложения и авторизуйте заново.',
            'response' => 'Google вернул некорректный ответ при обмене кода на токен.',
            'http' => 'Google отклонил обмен кода на токен.' . ($this->status !== null && $this->status >= 100 && $this->status <= 599 ? ' HTTP ' . $this->status . '.' : ''),
            default => 'Не удалось обменять код авторизации на токен.',
        };
    }
}
