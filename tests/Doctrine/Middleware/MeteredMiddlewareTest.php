<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredDriver;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredMiddleware;

final class MeteredMiddlewareTest extends TestCase
{
    public function testWrapReturnsMeteredDriver(): void
    {
        $middleware = new MeteredMiddleware('test');
        $inner = $this->createStub(Driver::class);

        self::assertInstanceOf(MeteredDriver::class, $middleware->wrap($inner));
    }
}
