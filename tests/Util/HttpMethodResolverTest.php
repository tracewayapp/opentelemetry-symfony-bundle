<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Util;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Util\HttpMethodResolver;

final class HttpMethodResolverTest extends TestCase
{
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
