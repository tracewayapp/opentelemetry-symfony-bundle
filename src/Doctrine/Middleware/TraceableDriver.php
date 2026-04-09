<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class TraceableDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly string $tracerName,
        private readonly bool $recordStatements,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        $connection = parent::connect($params);

        $args = [
            $connection,
            $this->tracerName,
            $this->recordStatements,
            $this->resolveDbSystem($params),
            $params['dbname'] ?? null,
            $params['host'] ?? null,
            isset($params['port']) ? (int) $params['port'] : null,
        ];

        return self::isDbal4()
            ? new TraceableConnectionDbal4(...$args)
            : new TraceableConnectionDbal3(...$args);
    }

    /**
     * VersionAwarePlatformDriver was removed in DBAL 4 — its absence signals DBAL 4+.
     */
    private static function isDbal4(): bool
    {
        /** @var bool|null $result */
        static $result = null;

        return $result ??= !interface_exists(\Doctrine\DBAL\VersionAwarePlatformDriver::class);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveDbSystem(array $params): string
    {
        $driver = isset($params['driver']) && \is_string($params['driver']) ? $params['driver'] : '';

        return match (true) {
            str_contains($driver, 'mysql') => 'mysql',
            str_contains($driver, 'pgsql') || str_contains($driver, 'postgres') => 'postgresql',
            str_contains($driver, 'sqlite') => 'sqlite',
            str_contains($driver, 'sqlsrv') || str_contains($driver, 'mssql') => 'mssql',
            str_contains($driver, 'oci') || str_contains($driver, 'oracle') => 'oracle',
            default => $driver !== '' ? $driver : 'other_sql',
        };
    }
}
