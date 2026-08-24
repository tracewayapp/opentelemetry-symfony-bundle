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
        private readonly bool $onlyWithParent = false,
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
            DbSystemResolver::resolve($params),
            $params['dbname'] ?? null,
            $params['host'] ?? null,
            isset($params['port']) ? (int) $params['port'] : null,
            $this->onlyWithParent,
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
}
