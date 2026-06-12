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
     * Value for the deprecated db.system attribute (dual-emitted for migration).
     */
    public static function legacyValue(string $dbSystem): string
    {
        return match ($dbSystem) {
            'microsoft.sql_server' => 'mssql',
            'oracle.db' => 'oracle',
            'ibm.db2' => 'db2',
            default => $dbSystem,
        };
    }
}
