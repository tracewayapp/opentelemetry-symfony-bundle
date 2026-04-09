<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableDriver;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableDriverTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testConnectReturnsVersionSpecificConnection(): void
    {
        $innerConnection = $this->createStub(Connection::class);
        $innerDriver = $this->createStub(DriverInterface::class);
        $innerDriver->method('connect')->willReturn($innerConnection);

        $driver = new TraceableDriver($innerDriver, 'test-tracer', false);
        $result = $driver->connect(['driver' => 'pdo_mysql', 'dbname' => 'shop', 'host' => 'db.local', 'port' => 3306]);

        $expected = interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class)
            ? \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal3::class
            : \Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableConnectionDbal4::class;

        self::assertInstanceOf($expected, $result);
    }

    #[DataProvider('dbSystemProvider')]
    public function testResolveDbSystem(string $driverParam, string $expectedSystem): void
    {
        $innerConnection = $this->createStub(Connection::class);
        $innerConnection->method('exec')->willReturn(0);
        $innerDriver = $this->createStub(DriverInterface::class);
        $innerDriver->method('connect')->willReturn($innerConnection);

        $driver = new TraceableDriver($innerDriver, 'test-tracer', false);
        $connection = $driver->connect(['driver' => $driverParam, 'dbname' => 'test_db']);

        $connection->exec('SELECT 1');

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame($expectedSystem, $attributes['db.system.name']);
        self::assertSame($expectedSystem, $attributes['db.system']);
        self::assertSame(SpanKind::KIND_CLIENT, $this->exporter->getSpans()[0]->getKind());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dbSystemProvider(): iterable
    {
        yield 'pdo_mysql' => ['pdo_mysql', 'mysql'];
        yield 'mysqli' => ['mysqli', 'mysql'];
        yield 'pdo_pgsql' => ['pdo_pgsql', 'postgresql'];
        yield 'pdo_sqlite' => ['pdo_sqlite', 'sqlite'];
        yield 'sqlite3' => ['sqlite3', 'sqlite'];
        yield 'pdo_sqlsrv' => ['pdo_sqlsrv', 'mssql'];
        yield 'oci8' => ['oci8', 'oracle'];
        yield 'unknown_driver' => ['some_custom_driver', 'some_custom_driver'];
        yield 'empty_driver' => ['', 'other_sql'];
    }

    public function testConnectWithoutOptionalParams(): void
    {
        $innerConnection = $this->createStub(Connection::class);
        $innerConnection->method('exec')->willReturn(0);
        $innerDriver = $this->createStub(DriverInterface::class);
        $innerDriver->method('connect')->willReturn($innerConnection);

        $driver = new TraceableDriver($innerDriver, 'test-tracer', false);
        $connection = $driver->connect([]);

        $connection->exec('SELECT 1');

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('other_sql', $attributes['db.system.name']);
        self::assertSame('other_sql', $attributes['db.system']);
        self::assertArrayNotHasKey('db.namespace', $attributes);
        self::assertArrayNotHasKey('db.name', $attributes);
        self::assertArrayNotHasKey('server.address', $attributes);
        self::assertArrayNotHasKey('server.port', $attributes);
    }
}
