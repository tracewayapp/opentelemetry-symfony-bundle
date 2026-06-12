<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\DbSystemResolver;

final class DbSystemResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function paramsProvider(): iterable
    {
        yield 'pdo_mysql' => [['driver' => 'pdo_mysql'], 'mysql'];
        yield 'mysqli' => [['driver' => 'mysqli'], 'mysql'];
        yield 'mariadb via serverVersion' => [['driver' => 'pdo_mysql', 'serverVersion' => '10.11.2-MariaDB'], 'mariadb'];
        yield 'mariadb prefix serverVersion' => [['driver' => 'mysqli', 'serverVersion' => 'mariadb-10.6'], 'mariadb'];
        yield 'pdo_pgsql' => [['driver' => 'pdo_pgsql'], 'postgresql'];
        yield 'pgsql native' => [['driver' => 'pgsql'], 'postgresql'];
        yield 'pdo_sqlite' => [['driver' => 'pdo_sqlite'], 'sqlite'];
        yield 'sqlite3' => [['driver' => 'sqlite3'], 'sqlite'];
        yield 'pdo_sqlsrv' => [['driver' => 'pdo_sqlsrv'], 'microsoft.sql_server'];
        yield 'sqlsrv' => [['driver' => 'sqlsrv'], 'microsoft.sql_server'];
        yield 'oci8' => [['driver' => 'oci8'], 'oracle.db'];
        yield 'pdo_oci' => [['driver' => 'pdo_oci'], 'oracle.db'];
        yield 'ibm_db2' => [['driver' => 'ibm_db2'], 'ibm.db2'];
        yield 'unknown driver' => [['driver' => 'custom_driver'], 'other_sql'];
        yield 'empty driver' => [['driver' => ''], 'other_sql'];
        yield 'missing driver' => [[], 'other_sql'];
    }

    #[DataProvider('paramsProvider')]
    public function testResolve(array $params, string $expected): void
    {
        self::assertSame($expected, DbSystemResolver::resolve($params));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyProvider(): iterable
    {
        yield 'mssql' => ['microsoft.sql_server', 'mssql'];
        yield 'oracle' => ['oracle.db', 'oracle'];
        yield 'db2' => ['ibm.db2', 'db2'];
        yield 'mysql unchanged' => ['mysql', 'mysql'];
        yield 'postgresql unchanged' => ['postgresql', 'postgresql'];
        yield 'mariadb unchanged' => ['mariadb', 'mariadb'];
    }

    #[DataProvider('legacyProvider')]
    public function testLegacyValue(string $stable, string $expectedLegacy): void
    {
        self::assertSame($expectedLegacy, DbSystemResolver::legacyValue($stable));
    }
}
