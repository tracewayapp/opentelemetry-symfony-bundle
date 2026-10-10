<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Metrics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Metrics\TemporalityResolver;

final class TemporalityResolverTest extends TestCase
{
    /** @return iterable<string, array{?string, string, bool, ?string}> */
    public static function cases(): iterable
    {
        yield 'unset changes nothing' => [null, 'fpm-fcgi', false, null];
        yield 'explicit delta' => ['delta', 'cli', false, 'delta'];
        yield 'explicit cumulative' => ['cumulative', 'fpm-fcgi', false, 'cumulative'];
        yield 'explicit lowmemory' => ['lowmemory', 'cli', false, 'lowmemory'];
        yield 'auto under PHP-FPM' => ['auto', 'fpm-fcgi', false, 'delta'];
        yield 'auto under CGI' => ['auto', 'cgi-fcgi', false, 'delta'];
        yield 'auto under mod_php' => ['auto', 'apache2handler', false, 'delta'];
        yield 'auto under LiteSpeed' => ['auto', 'litespeed', false, 'delta'];
        yield 'auto under the built-in server' => ['auto', 'cli-server', false, 'delta'];
        yield 'auto under FrankenPHP classic mode' => ['auto', 'frankenphp', false, 'delta'];
        yield 'auto under a FrankenPHP worker' => ['auto', 'frankenphp', true, null];
        yield 'auto under the CLI (console, Messenger, RoadRunner, Swoole)' => ['auto', 'cli', false, null];
    }

    #[DataProvider('cases')]
    public function testResolve(?string $configured, string $sapi, bool $frankenPhpWorker, ?string $expected): void
    {
        self::assertSame($expected, TemporalityResolver::resolve($configured, $sapi, $frankenPhpWorker));
    }
}
