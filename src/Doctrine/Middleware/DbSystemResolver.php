<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

/**
 * @internal Maps Doctrine connection params to stable db.system.name enum values.
 */
final class DbSystemResolver
{
    /**
     * @param array<string, mixed> $params
     */
    public static function resolve(array $params): string
    {
        $driver = isset($params['driver']) && \is_string($params['driver']) ? strtolower($params['driver']) : '';
        $serverVersion = isset($params['serverVersion']) && \is_string($params['serverVersion']) ? strtolower($params['serverVersion']) : '';

        return match (true) {
            str_contains($driver, 'mysql') => str_contains($serverVersion, 'mariadb') ? 'mariadb' : 'mysql',
            str_contains($driver, 'pgsql') || str_contains($driver, 'postgres') => 'postgresql',
            str_contains($driver, 'sqlite') => 'sqlite',
            str_contains($driver, 'sqlsrv') || str_contains($driver, 'mssql') => 'microsoft.sql_server',
            str_contains($driver, 'oci') || str_contains($driver, 'oracle') => 'oracle.db',
            str_contains($driver, 'ibm') || str_contains($driver, 'db2') => 'ibm.db2',
            default => 'other_sql',
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function serverAddress(array $params): ?string
    {
        return self::isNetworked($params) && isset($params['host']) && \is_string($params['host']) ? $params['host'] : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function serverPort(array $params): ?int
    {
        return self::isNetworked($params) && isset($params['port']) && is_numeric($params['port']) ? (int) $params['port'] : null;
    }

    /**
     * SQLite runs in-process: DBAL's DSN parser still fills in host=localhost for sqlite:/// URLs.
     *
     * @param array<string, mixed> $params
     */
    private static function isNetworked(array $params): bool
    {
        return 'sqlite' !== self::resolve($params);
    }
}
