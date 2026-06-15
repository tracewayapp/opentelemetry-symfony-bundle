<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Util;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Util\UrlSanitizer;

final class UrlSanitizerTest extends TestCase
{
    public function testCredentialsRedacted(): void
    {
        self::assertSame(
            'https://REDACTED:REDACTED@example.com/path',
            UrlSanitizer::sanitizeUrl('https://user:pass@example.com/path'),
        );
    }

    public function testUserOnlyCredentialRedacted(): void
    {
        self::assertSame(
            'https://REDACTED:REDACTED@example.com/',
            UrlSanitizer::sanitizeUrl('https://token@example.com/'),
        );
    }

    public function testUrlWithoutCredentialsUnchanged(): void
    {
        self::assertSame(
            'https://example.com/path?page=2',
            UrlSanitizer::sanitizeUrl('https://example.com/path?page=2'),
        );
    }

    public function testSensitiveQueryParamsRedactedInUrl(): void
    {
        self::assertSame(
            'https://example.com/file?sig=REDACTED&page=2',
            UrlSanitizer::sanitizeUrl('https://example.com/file?sig=abc123&page=2'),
        );
    }

    public function testAllSemconvSensitiveParamsRedacted(): void
    {
        self::assertSame(
            'X-Amz-Signature=REDACTED&X-Amz-Credential=REDACTED&X-Amz-Security-Token=REDACTED&sig=REDACTED&X-Goog-Signature=REDACTED',
            UrlSanitizer::sanitizeQuery('X-Amz-Signature=s3cr3t&X-Amz-Credential=AKIA123&X-Amz-Security-Token=tok&sig=abc&X-Goog-Signature=xyz'),
        );
    }

    public function testFragmentPreserved(): void
    {
        self::assertSame(
            'https://example.com/file?sig=REDACTED#section',
            UrlSanitizer::sanitizeUrl('https://example.com/file?sig=abc#section'),
        );
    }

    public function testCaseSensitiveParamMatching(): void
    {
        self::assertSame(
            'SIG=abc&page=2',
            UrlSanitizer::sanitizeQuery('SIG=abc&page=2'),
        );
    }

    public function testAtSignInQueryNotTreatedAsCredentials(): void
    {
        self::assertSame(
            'https://example.com/search?email=a@b.com',
            UrlSanitizer::sanitizeUrl('https://example.com/search?email=a@b.com'),
        );
    }
}
