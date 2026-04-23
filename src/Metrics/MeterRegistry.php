<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Metrics;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Lazily creates and caches OpenTelemetry instruments bound to a single meter.
 *
 * Inject {@see MeterRegistryInterface} wherever you need manual instrumentation:
 *
 *     $counter = $this->metrics->counter('media.downloads', description: 'Media downloads by outcome');
 *     $counter->add(1, ['outcome' => 'success']);
 *
 * The meter itself is resolved lazily from {@see Globals::meterProvider()}, so the
 * service is safe to inject even when the OpenTelemetry SDK is not configured
 * (the NoOp provider exposes no-op instruments).
 */
final class MeterRegistry implements MeterRegistryInterface, ResetInterface
{
    private ?MeterInterface $meter = null;

    /** @var array<string, CounterInterface> */
    private array $counters = [];

    /** @var array<string, HistogramInterface> */
    private array $histograms = [];

    /** @var array<string, UpDownCounterInterface> */
    private array $upDownCounters = [];

    public function __construct(
        private readonly string $meterName = 'opentelemetry-symfony',
    ) {}

    public function counter(string $name, ?string $unit = null, ?string $description = null): CounterInterface
    {
        return $this->counters[$name] ??= $this->getMeter()->createCounter($name, $unit, $description);
    }

    public function histogram(string $name, ?string $unit = null, ?string $description = null): HistogramInterface
    {
        return $this->histograms[$name] ??= $this->getMeter()->createHistogram($name, $unit, $description);
    }

    public function upDownCounter(string $name, ?string $unit = null, ?string $description = null): UpDownCounterInterface
    {
        return $this->upDownCounters[$name] ??= $this->getMeter()->createUpDownCounter($name, $unit, $description);
    }

    public function reset(): void
    {
        $this->meter = null;
        $this->counters = [];
        $this->histograms = [];
        $this->upDownCounters = [];
    }

    private function getMeter(): MeterInterface
    {
        return $this->meter ??= Globals::meterProvider()->getMeter($this->meterName);
    }
}
