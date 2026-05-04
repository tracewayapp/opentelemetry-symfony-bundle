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

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function dbSystemProvider(): iterable
    {
        yield 'mysql driver' => ['pdo_mysql', 'mysql'];
        yield 'postgres pdo' => ['pdo_pgsql', 'postgresql'];
        yield 'postgres native' => ['pgsql', 'postgresql'];
        yield 'sqlite' => ['pdo_sqlite', 'sqlite'];
        yield 'sqlsrv' => ['pdo_sqlsrv', 'mssql'];
        yield 'mssql' => ['mssql', 'mssql'];
        yield 'oci' => ['oci8', 'oracle'];
        yield 'oracle' => ['pdo_oracle', 'oracle'];
        yield 'unknown' => ['custom_driver', 'custom_driver'];
        yield 'empty' => ['', 'other_sql'];
    }

    /**
     * @dataProvider dbSystemProvider
     */
    public function testResolvesDbSystemFromDriverString(string $driverName, string $expectedSystem): void
    {
        $inner = $this->createStub(Driver::class);
        $inner->method('connect')->willReturn($this->createStub(Connection::class));

        $reflection = new \ReflectionClass(MeteredDriver::class);
        $method = $reflection->getMethod('resolveDbSystem');
        $method->setAccessible(true);

        $driver = new MeteredDriver($inner, 'test');
        self::assertSame($expectedSystem, $method->invoke($driver, ['driver' => $driverName]));
    }

    public function testResolvesOtherSqlWhenDriverParamIsMissing(): void
    {
        $inner = $this->createStub(Driver::class);
        $inner->method('connect')->willReturn($this->createStub(Connection::class));

        $reflection = new \ReflectionClass(MeteredDriver::class);
        $method = $reflection->getMethod('resolveDbSystem');
        $method->setAccessible(true);

        $driver = new MeteredDriver($inner, 'test');
        self::assertSame('other_sql', $method->invoke($driver, []));
    }
}
