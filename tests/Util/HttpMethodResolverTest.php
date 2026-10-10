<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Util;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Util\HttpMethodResolver;

final class HttpMethodResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS']);
        self::resetKnownMethods();
    }

    public function testKnownMethodsCanBeOverriddenFromTheEnvironment(): void
    {
        $_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS'] = ' get, purge ,,';
        self::resetKnownMethods();

        self::assertSame('PURGE', HttpMethodResolver::normalize('PURGE'));
        self::assertSame('GET', HttpMethodResolver::normalize('GET'));
        self::assertSame('_OTHER', HttpMethodResolver::normalize('POST'), 'the override replaces the default list');
    }

    public function testBlankOverrideFallsBackToTheDefaultList(): void
    {
        $_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS'] = ' , ';
        self::resetKnownMethods();

        self::assertSame('POST', HttpMethodResolver::normalize('POST'));
    }

    private static function resetKnownMethods(): void
    {
        (new \ReflectionProperty(HttpMethodResolver::class, 'knownMethods'))->setValue(null, null);
    }

    public function testKnownMethodPassesThrough(): void
    {
        self::assertSame('GET', HttpMethodResolver::normalize('GET'));
        self::assertSame('PATCH', HttpMethodResolver::normalize('PATCH'));
    }

    public function testLowercaseKnownMethodIsUppercased(): void
    {
        self::assertSame('GET', HttpMethodResolver::normalize('get'));
        self::assertSame('POST', HttpMethodResolver::normalize('Post'));
    }

    public function testUnknownMethodBecomesOther(): void
    {
        self::assertSame('_OTHER', HttpMethodResolver::normalize('FOO'));
        self::assertSame('_OTHER', HttpMethodResolver::normalize('PROPFIND'));
    }

    public function testSpanNameMethodForKnown(): void
    {
        self::assertSame('DELETE', HttpMethodResolver::spanNameMethod('delete'));
    }

    public function testSpanNameMethodForUnknownIsHttp(): void
    {
        self::assertSame('HTTP', HttpMethodResolver::spanNameMethod('FOO'));
    }
}
