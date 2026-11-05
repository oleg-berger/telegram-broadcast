<?php

declare(strict_types=1);

namespace Broadcast\Tests\Http;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\DeepLTranslator;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeepLTranslatorTest extends TestCase
{
    public function testTranslateUsesAuthenticatedJsonApiAndReturnsTranslatedText(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"translations":[{"detected_source_language":"EN","text":"Hallo"}]}'));
        $translator = new DeepLTranslator('deepl-secret', 'https://api-free.deepl.com/', $http);

        $translated = $translator->translate('Hello', 'de');

        self::assertSame('Hallo', $translated);
        self::assertSame('https://api-free.deepl.com/v2/translate', $http->requests[0]['url']);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Authorization' => 'DeepL-Auth-Key deepl-secret'],
            $http->requests[0]['headers'],
        );
        self::assertSame(30, $http->requests[0]['timeout']);
        self::assertSame(
            ['text' => ['Hello'], 'target_lang' => 'DE'],
            json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeBaseUrls(): iterable
    {
        yield 'http' => ['http://api.deepl.com'];
        yield 'lookalike host' => ['https://api.deepl.com.evil.example'];
        yield 'embedded credentials' => ['https://user:password@api.deepl.com'];
        yield 'unexpected path' => ['https://api.deepl.com/proxy'];
        yield 'query string' => ['https://api.deepl.com?redirect=evil'];
    }

    #[DataProvider('unsafeBaseUrls')]
    public function testConstructorRejectsUnsafeDeepLBaseUrl(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DeepLTranslator('key', $baseUrl, new FakeHttpClient());
    }

    /** @return iterable<string, array{int, bool, ?int}> */
    public static function apiFailures(): iterable
    {
        yield 'forbidden' => [403, false, null];
        yield 'quota exceeded' => [456, false, null];
        yield 'rate limited' => [429, true, 9];
        yield 'server failure' => [500, true, null];
        yield 'bad request' => [400, false, null];
    }

    #[DataProvider('apiFailures')]
    public function testApiFailuresAreClassifiedWithoutLeakingSecretsOrText(
        int $status,
        bool $transient,
        ?int $retryAfter,
    ): void {
        $http = new FakeHttpClient();
        $headers = $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter];
        $http->respond(new HttpResponse($status, '{"message":"private-text and deepl-secret"}', $headers));
        $translator = new DeepLTranslator('deepl-secret', 'https://api.deepl.com', $http);

        try {
            $translator->translate('private-text', 'DE');
            self::fail('Expected an API failure.');
        } catch (ApiFailure $failure) {
            self::assertSame($transient, $failure->transient);
            self::assertSame($retryAfter, $failure->retryAfter);
            self::assertFalse($failure->blocked);
            self::assertStringNotContainsString('private-text', $failure->getMessage());
            self::assertStringNotContainsString('deepl-secret', $failure->getMessage());
        }
    }

    public function testTransportTimeoutIsReportedAsSafeTransientFailure(): void
    {
        $http = new FakeHttpClient();
        $http->fail(new HttpTransportException('private-text', true));
        $translator = new DeepLTranslator('deepl-secret', 'https://api.deepl.com', $http);

        try {
            $translator->translate('private-text', 'DE');
            self::fail('Expected an API failure.');
        } catch (ApiFailure $failure) {
            self::assertTrue($failure->transient);
            self::assertStringNotContainsString('private-text', $failure->getMessage());
            self::assertStringNotContainsString('deepl-secret', $failure->getMessage());
        }
    }

    public function testMalformedSuccessResponseIsTransientFailure(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"translations":[]}'));
        $translator = new DeepLTranslator('key', 'https://api.deepl.com', $http);

        $this->expectExceptionObject(new ApiFailure('DeepL returned an invalid response.', true));
        $translator->translate('Hello', 'DE');
    }

    public static function invalidTranslations(): iterable
    {
        foreach (['{', '{}', '{"translations":[]}', '{"translations":[{"text":""}]}', '{"translations":[{"text":"   "}]}', '{"translations":[{"text":42}]}', '{"translations":[{"text":"one"},{"text":"two"}]}'] as $body) {
            yield [$body];
        }
    }

    #[DataProvider('invalidTranslations')]
    public function testRejectsMissingEmptyOrAmbiguousTranslations(string $body): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, $body));
        $translator = new DeepLTranslator('key', 'https://api-free.deepl.com', $http);
        $this->expectExceptionObject(new ApiFailure('DeepL returned an invalid response.', true));
        $translator->translate('Private text', 'FR');
    }

    public function testAllBotLanguagesArePassedToDeepLWithoutModelOrPrompt(): void
    {
        foreach (['RU', 'EN-GB', 'ES', 'FR'] as $language) {
            $http = new FakeHttpClient();
            $http->respond(new HttpResponse(200, '{"translations":[{"text":"Translated"}]}'));
            $translator = new DeepLTranslator('key', 'https://api.deepl.com', $http);
            self::assertSame('Translated', $translator->translate('Source', $language));
            self::assertSame(['text' => ['Source'], 'target_lang' => $language], json_decode($http->requests[0]['body'], true));
        }
    }
}
