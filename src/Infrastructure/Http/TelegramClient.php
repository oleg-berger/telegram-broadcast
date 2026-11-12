<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Http;

use Broadcast\Application\AttachmentTelegramGateway;
use Broadcast\Domain\Attachment;
use Broadcast\Domain\ApiFailure;
use Broadcast\Application\TelegramKeyboardGateway;
use JsonException;

final class TelegramClient implements AttachmentTelegramGateway, TelegramKeyboardGateway
{
    private const string API_BASE = 'https://api.telegram.org';

    private readonly HttpClient $http;

    /** @param ?\Closure(string,callable(string):int):int $download Testable streaming HTTP boundary. */
    public function __construct(private readonly string $token, ?HttpClient $http = null, private readonly ?\Closure $download = null)
    {
        $this->http = $http ?? new CurlHttpClient();
    }

    public function sendMessage(int $chatId, string $text, array $options = []): void
    {
        unset($options['parse_mode']);
        $this->call('sendMessage', [
            ...$options,
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    public function editKeyboard(int $chatId, int $messageId, array $rows): void
    {
        $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId, 'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => $rows],
        ]);
    }

    public function sendPhoto(int $chatId, string $path, string $caption): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) { throw new ApiFailure('Telegram photo is unavailable.'); }
        $boundary = 'bot-' . bin2hex(random_bytes(16));
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"chat_id\"\r\n\r\n$chatId\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"caption\"\r\n\r\n$caption\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"help-card.png\"\r\nContent-Type: image/png\r\n\r\n"
            . $contents . "\r\n--$boundary--\r\n";
        try {
            $response = $this->http->post(self::API_BASE . '/bot' . $this->token . '/sendPhoto', ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body, 30);
        } catch (HttpTransportException) {
            throw new ApiFailure('Telegram photo upload failed.', true);
        }
        try {
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($response->status >= 400) { throw $this->apiFailure($response->status); }
            throw new ApiFailure('Telegram returned an invalid response.', true);
        }
        if ($response->status >= 400 || ($decoded['ok'] ?? false) !== true) {
            $code = is_int($decoded['error_code'] ?? null) ? $decoded['error_code'] : $response->status;
            $retry = $decoded['parameters']['retry_after'] ?? null;
            throw $this->apiFailure($code, is_int($retry) ? $retry : null);
        }
    }

    public function getUpdates(int $offset, int $timeout = 25): array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], max(1, $timeout + 5));

        if (!is_array($result) || !array_is_list($result)) {
            throw new ApiFailure('Telegram returned an invalid response.', true);
        }

        return $result;
    }

    public function answerCallbackQuery(string $id): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $id]);
    }

    public function sendDocument(int $chatId, string $path, string $filename): void
    {
        try {
            Attachment::validateName($filename);
            $contents = file_get_contents($path, false, null, 0, Attachment::MAX_BYTES + 1);
            if ($contents === false || strlen($contents) > Attachment::MAX_BYTES) {
                throw new \RuntimeException();
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
        } catch (\Throwable) {
            throw new ApiFailure('Telegram document is unavailable.');
        }
        $this->uploadDocument($chatId, $contents, $filename, $mime);
    }

    public function sendAttachment(int $chatId, Attachment $attachment, string $path): void
    {
        try {
            $contents = file_get_contents($path, false, null, 0, Attachment::MAX_BYTES + 1);
            if ($contents === false || strlen($contents) !== $attachment->byteSize
                || !hash_equals($attachment->sha256, hash('sha256', $contents))) {
                throw new \RuntimeException();
            }
        } catch (\Throwable) {
            throw new ApiFailure('Telegram document is unavailable.');
        }
        $this->uploadDocument($chatId, $contents, $attachment->displayName, $attachment->mime);
    }

    private function uploadDocument(int $chatId, string $contents, string $filename, string $mime): void
    {
        $boundary = 'bot-' . bin2hex(random_bytes(16));
        $filename = addcslashes($filename, '"\\');
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"chat_id\"\r\n\r\n$chatId\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"document\"; filename=\"$filename\"\r\nContent-Type: $mime\r\n\r\n"
            . $contents . "\r\n--$boundary--\r\n";
        try {
            $timeout = strlen($contents) >= 10_000_000 ? 120 : 30;
            $response = $this->http->post(self::API_BASE . '/bot' . $this->token . '/sendDocument', ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body, $timeout);
        } catch (HttpTransportException $failure) {
            throw $this->transportFailure($failure, true);
        }
        $this->decodeResponse($response, true);
    }

    public function filePath(string $fileId): string
    {
        if ($fileId === '' || strlen($fileId) > 512 || preg_match('/[\x00-\x20\x7f]/', $fileId)) {
            throw new ApiFailure('Invalid Telegram file.');
        }
        $result = $this->call('getFile', ['file_id' => $fileId]);
        $path = is_array($result) ? ($result['file_path'] ?? null) : null;
        if (!is_string($path) || !self::safeRemotePath($path)
            || (isset($result['file_size']) && (!is_int($result['file_size']) || $result['file_size'] < 0 || $result['file_size'] > Attachment::MAX_BYTES))) {
            throw new ApiFailure('Invalid Telegram file.');
        }
        return $path;
    }

    public function downloadFile(string $remotePath, string $targetPath, int $maxBytes = 20_000_000): void
    {
        if (!self::safeRemotePath($remotePath) || $maxBytes < 1 || $maxBytes > Attachment::MAX_BYTES) {
            throw new ApiFailure('Invalid Telegram file.');
        }
        $stream = null;
        $created = false;
        $oversized = false;
        try {
            // Exclusive create never follows or truncates an existing destination.
            $stream = @fopen($targetPath, 'x+b');
            if ($stream === false) { throw new \RuntimeException(); }
            $created = true;
            $bytes = 0;
            $write = static function (string $chunk) use ($stream, &$bytes, &$oversized, $maxBytes): int {
                $length = strlen($chunk);
                if ($length > $maxBytes - $bytes) { $oversized = true; return 0; }
                $written = fwrite($stream, $chunk);
                if ($written !== $length) { return 0; }
                $bytes += $written;
                return $written;
            };
            $url = self::API_BASE . '/file/bot' . $this->token . '/' . $remotePath;
            $status = $this->download !== null ? ($this->download)($url, $write) : $this->streamDownload($url, $write);
            if ($oversized || $status !== 200) { throw new ApiFailure('Telegram file download failed.', !$oversized && ($status === 429 || $status >= 500)); }
            fflush($stream);
            fclose($stream);
        } catch (\Throwable $failure) {
            if (is_resource($stream)) { fclose($stream); }
            if ($created && is_file($targetPath)) { @unlink($targetPath); }
            throw new ApiFailure('Telegram file download failed.', !$oversized && $failure instanceof ApiFailure && $failure->transient);
        }
    }

    private static function safeRemotePath(string $path): bool
    {
        return strlen($path) <= 1024 && (bool) preg_match('/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_.-]+)+\z/', $path)
            && !in_array('..', explode('/', $path), true) && !in_array('.', explode('/', $path), true);
    }

    private function streamDownload(string $url, callable $write): int
    {
        $handle = curl_init();
        if ($handle === false) { throw new ApiFailure('Telegram file download failed.', true); }
        if (!curl_setopt_array($handle, [CURLOPT_URL => $url, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static fn ($unused, string $chunk): int => $write($chunk)])) {
            throw new ApiFailure('Telegram file download failed.', true);
        }
        if (curl_exec($handle) === false) { throw new ApiFailure('Telegram file download failed.', true); }
        return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    }

    public function setCommands(array $commands, array $scope = ['type' => 'default'], string $language = ''): void
    {
        $this->call('setMyCommands', ['commands' => $commands, 'scope' => $scope, 'language_code' => $language]);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $method, array $payload, int $timeoutSeconds = 30): mixed
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ApiFailure('Telegram request could not be encoded.');
        }

        try {
            $response = $this->http->post(
                self::API_BASE . '/bot' . $this->token . '/' . $method,
                ['Content-Type' => 'application/json'],
                $body,
                $timeoutSeconds,
            );
        } catch (HttpTransportException $exception) {
            throw $this->transportFailure($exception, $method === 'sendMessage');
        }

        return $this->decodeResponse($response, $method === 'sendMessage', $method === 'editMessageReplyMarkup');
    }

    private function decodeResponse(HttpResponse $response, bool $sending, bool $editingKeyboard = false): mixed
    {
        try {
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($response->status >= 400) {
                throw $this->apiFailure($response->status, uncertain: $sending && $response->status >= 500);
            }
            throw new ApiFailure('Telegram returned an invalid response.', true, uncertain: $sending);
        }

        if (!is_array($decoded)) {
            if ($response->status >= 400) {
                throw $this->apiFailure($response->status, uncertain: $sending && $response->status >= 500);
            }
            throw new ApiFailure('Telegram returned an invalid response.', true, uncertain: $sending);
        }

        if ($response->status >= 400 || ($decoded['ok'] ?? false) !== true) {
            // Retrying a successful keyboard edit is an idempotent operation.
            if ($editingKeyboard && $response->status === 400 && ($decoded['error_code'] ?? null) === 400
                && is_string($decoded['description'] ?? null)
                && str_starts_with($decoded['description'], 'Bad Request: message is not modified')) {
                return true;
            }
            $errorCode = is_int($decoded['error_code'] ?? null)
                ? $decoded['error_code']
                : $response->status;
            $retryAfter = $decoded['parameters']['retry_after'] ?? null;
            $retryAfter = is_int($retryAfter) && $retryAfter >= 0 ? $retryAfter : null;

            $explicitRejection = ($decoded['ok'] ?? null) === false && is_int($decoded['error_code'] ?? null) && $decoded['error_code'] >= 400;
            $uncertain = $sending && !$explicitRejection && ($response->status < 400 || $response->status >= 500);
            throw $this->apiFailure($errorCode, $retryAfter, $uncertain);
        }

        if (!array_key_exists('result', $decoded)) {
            throw new ApiFailure('Telegram returned an invalid response.', true, uncertain: $sending);
        }

        if ($sending && (!is_array($decoded['result']) || !is_int($decoded['result']['message_id'] ?? null) || $decoded['result']['message_id'] <= 0)) {
            throw new ApiFailure('Telegram returned an invalid response.', true, uncertain: true);
        }

        return $decoded['result'];
    }

    private function transportFailure(HttpTransportException $failure, bool $sending): ApiFailure
    {
        // Only these cURL failures establish that no application request reached Telegram.
        $beforeSend = in_array($failure->transportCode, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT], true);
        return new ApiFailure($failure->timeout ? 'Telegram request timed out.' : 'Telegram request failed.', true, uncertain: $sending && !$beforeSend);
    }

    private function apiFailure(int $errorCode, ?int $retryAfter = null, bool $uncertain = false): ApiFailure
    {
        return new ApiFailure(
            'Telegram API request failed.',
            $errorCode === 429 || $errorCode >= 500,
            $retryAfter,
            $errorCode === 403,
            $uncertain,
        );
    }
}
