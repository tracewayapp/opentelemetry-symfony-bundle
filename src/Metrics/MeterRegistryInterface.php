<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Metrics;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

/**
 * Lazy registry for OpenTelemetry instruments bound to a single meter.
 *
 * Inject this service to record counters, histograms and up/down counters
 * without dealing with MeterProvider / MeterInterface boilerplate.
 */
interface MeterRegistryInterface
{
    public function counter(string $name, ?string $unit = null, ?string $description = null): CounterInterface;

    public function histogram(string $name, ?string $unit = null, ?string $description = null): HistogramInterface;

    public function upDownCounter(string $name, ?string $unit = null, ?string $description = null): UpDownCounterInterface;
}
