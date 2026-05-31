<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

interface GlobalsAccessorInterface
{
    public function tracerProvider(): TracerProviderInterface;

    public function meterProvider(): MeterProviderInterface;

    public function loggerProvider(): LoggerProviderInterface;

    public function propagator(): TextMapPropagatorInterface;
}
