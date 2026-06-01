<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

final class GlobalsAccessor implements GlobalsAccessorInterface
{
    public function tracerProvider(): TracerProviderInterface
    {
        return Globals::tracerProvider();
    }

    public function meterProvider(): MeterProviderInterface
    {
        return Globals::meterProvider();
    }

    public function loggerProvider(): LoggerProviderInterface
    {
        return Globals::loggerProvider();
    }

    public function propagator(): TextMapPropagatorInterface
    {
        return Globals::propagator();
    }
}
