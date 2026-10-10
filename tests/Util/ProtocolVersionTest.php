<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Util\ProtocolVersion;

final class ProtocolVersionTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function serverProtocols(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'bare prefix' => ['HTTP/', null];
        yield 'HTTP/1.0' => ['HTTP/1.0', '1.0'];
        yield 'HTTP/1.1' => ['HTTP/1.1', '1.1'];
        yield 'HTTP/2.0 is reported as 2' => ['HTTP/2.0', '2'];
        yield 'HTTP/2' => ['HTTP/2', '2'];
        yield 'HTTP/3.0 is reported as 3' => ['HTTP/3.0', '3'];
    }

    #[DataProvider('serverProtocols')]
    public function testFromServerProtocol(?string $protocol, ?string $expected): void
    {
        self::assertSame($expected, ProtocolVersion::fromServerProtocol($protocol));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function transportInfo(): iterable
    {
        yield 'CURL_HTTP_VERSION_1_0' => [1, '1.0'];
        yield 'CURL_HTTP_VERSION_1_1' => [2, '1.1'];
        yield 'CURL_HTTP_VERSION_2_0' => [3, '2'];
        yield 'CURL_HTTP_VERSION_3' => [30, '3'];
        yield 'unknown curl constant' => [99, null];
        yield 'string 2.0' => ['2.0', '2'];
        yield 'string 3.0' => ['3.0', '3'];
        yield 'string 1.1 passes through' => ['1.1', '1.1'];
        yield 'empty string' => ['', null];
        yield 'null' => [null, null];
        yield 'not a version' => [['1.1'], null];
    }

    #[DataProvider('transportInfo')]
    public function testFromTransportInfo(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, ProtocolVersion::fromTransportInfo($raw));
    }
}
