<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableDriver;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware;

final class TraceableMiddlewareTest extends TestCase
{
    public function testWrapReturnsTraceableDriver(): void
    {
        $middleware = new TraceableMiddleware('test-tracer', false);
        $inner = $this->createStub(DriverInterface::class);

        $result = $middleware->wrap($inner);

        self::assertInstanceOf(TraceableDriver::class, $result);
    }
}
