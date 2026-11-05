<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Http;

use InvalidArgumentException;

final class CurlHttpClient implements HttpClient
{
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('HTTP timeout must be positive.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('HTTP client initialization failed.');
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $configured = curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = trim(substr($line, 0, $separator));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }

                return strlen($line);
            },
        ]);

        if (!$configured) {
            throw new HttpTransportException('HTTP client configuration failed.');
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $errorCode = curl_errno($handle);
            $timeout = $errorCode === CURLE_OPERATION_TIMEDOUT;
            throw new HttpTransportException(
                $timeout ? 'HTTP request timed out.' : 'HTTP request failed.',
                $timeout,
                $errorCode,
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, $responseBody, $responseHeaders);
    }
}
