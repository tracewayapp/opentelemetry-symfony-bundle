<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;

final class MeteredMiddleware implements Middleware
{
    public function __construct(
        private readonly string $meterName = 'opentelemetry-symfony',
    ) {}

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new MeteredDriver($driver, $this->meterName);
    }
}
