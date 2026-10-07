<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;

final class TraceableMiddleware implements Middleware
{
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        private readonly bool $recordStatements = true,
        private readonly bool $onlyWithParent = false,
        private readonly int $maxSpansPerTrace = 0,
    ) {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new TraceableDriver(
            $driver,
            $this->tracerName,
            $this->recordStatements,
            $this->onlyWithParent,
            $this->maxSpansPerTrace > 0 ? new DbSpanBudget($this->maxSpansPerTrace) : null,
        );
    }
}
