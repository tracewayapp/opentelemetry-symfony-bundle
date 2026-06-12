<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredConnectionDbal3;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredConnectionDbal4;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredDriver;

final class MeteredDriverTest extends TestCase
{
    public function testConnectReturnsMeteredConnection(): void
    {
        $inner = $this->createStub(Driver::class);
        $inner->method('connect')->willReturn($this->createStub(Connection::class));

        $driver = new MeteredDriver($inner, 'test');
        $connection = $driver->connect(['driver' => 'pdo_pgsql', 'dbname' => 'app_db', 'host' => 'db.local', 'port' => 5432]);

        $expected = interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)
            ? MeteredConnectionDbal3::class
            : MeteredConnectionDbal4::class;

        self::assertInstanceOf($expected, $connection);
    }
}
