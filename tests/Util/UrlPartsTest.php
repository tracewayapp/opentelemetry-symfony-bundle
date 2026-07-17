<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Util;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Util\UrlParts;

final class UrlPartsTest extends TestCase
{
    public function testHostStripsIpv6Brackets(): void
    {
        $parsed = parse_url('https://[::1]:8080/path');
        self::assertIsArray($parsed);

        self::assertSame('::1', UrlParts::host($parsed));
        self::assertSame(8080, UrlParts::port($parsed));
    }

    public function testHostPassesThroughRegularHostnames(): void
    {
        $parsed = parse_url('https://api.example.com/path');
        self::assertIsArray($parsed);

        self::assertSame('api.example.com', UrlParts::host($parsed));
    }

    public function testHostReturnsNullWithoutHost(): void
    {
        $parsed = parse_url('/relative/path');
        self::assertIsArray($parsed);

        self::assertNull(UrlParts::host($parsed));
    }

    public function testPortDefaultsByScheme(): void
    {
        $https = parse_url('https://example.com/');
        $http = parse_url('http://example.com/');
        $other = parse_url('ftp://example.com/');
        self::assertIsArray($https);
        self::assertIsArray($http);
        self::assertIsArray($other);

        self::assertSame(443, UrlParts::port($https));
        self::assertSame(80, UrlParts::port($http));
        self::assertNull(UrlParts::port($other));
    }

    public function testJoinResolvesReferenceForms(): void
    {
        self::assertSame('https://h/v1/users', UrlParts::join('https://h/v1/', '/v1/users'));
        self::assertSame('https://h:8443/v1/users', UrlParts::join('https://h:8443/v1/', 'users'));
        self::assertSame('https://h/v1/x?page=2', UrlParts::join('https://h/v1/x', '?page=2'));
        self::assertSame('https://other/z', UrlParts::join('https://h/v1/', 'https://other/z'));
        self::assertSame('https://other/z', UrlParts::join('https://h/v1/', '//other/z'));
        self::assertSame('https://h/v1/x', UrlParts::join('https://h/v1/x', ''));
    }

    public function testExplicitPortWins(): void
    {
        $parsed = parse_url('https://example.com:8443/');
        self::assertIsArray($parsed);

        self::assertSame(8443, UrlParts::port($parsed));
    }
}
