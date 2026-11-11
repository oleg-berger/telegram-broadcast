<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\WhatsApp;

use Broadcast\Infrastructure\Http\HttpResponse;

final class CurlWhatsAppHttpClient implements WhatsAppHttpClient
{
    public const int MAX_RESPONSE_BYTES = 3 * 1024 * 1024;
    public const int MAX_MEDIA_REQUEST_BYTES = 22_435_160;

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $fileRequest = $method === 'POST' && (bool) preg_match('~/api/[A-Za-z0-9_-]{1,64}/send-file\z~', parse_url($url, PHP_URL_PATH) ?: '');
        if (!in_array($method, ['GET', 'POST'], true) || $timeoutSeconds < 1 || $timeoutSeconds > 75
            || ($body !== null && strlen($body) > ($fileRequest ? self::MAX_MEDIA_REQUEST_BYTES : 131072))) {
            throw new WhatsAppHttpFailure(false);
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new WhatsAppHttpFailure(false);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value)) {
                throw new WhatsAppHttpFailure(false);
            }
            $lines[] = $name . ': ' . $value;
        }
        $response = '';
        $responseHeaders = [];
        $headerBytes = 0;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Never send the private bearer token through an environment-configured proxy.
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use (&$responseHeaders, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 16384) {
                    return 0;
                }
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $responseHeaders[trim(substr($line, 0, $separator))] = trim(substr($line, $separator + 1));
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (!curl_setopt_array($handle, $options)) {
            throw new WhatsAppHttpFailure(false);
        }
        if (curl_exec($handle) === false) {
            $beforeSend = in_array(curl_errno($handle), [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT], true);
            throw new WhatsAppHttpFailure(!$beforeSend);
        }
        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response, $responseHeaders);
    }
}
