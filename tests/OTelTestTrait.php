<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LoggerProviderBuilder;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

trait OTelTestTrait
{
    protected InMemoryExporter $exporter;
    protected InMemoryLogExporter $logExporter;
    protected InMemoryMetricExporter $metricExporter;
    protected ExportingReader $metricReader;

    protected function setUpOTel(): void
    {
        Globals::reset();
        $this->exporter = new InMemoryExporter();
        $this->logExporter = new InMemoryLogExporter();
        $this->metricExporter = new InMemoryMetricExporter();
        $this->metricReader = new ExportingReader($this->metricExporter);

        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $loggerProvider = (new LoggerProviderBuilder())
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($this->logExporter))
            ->build();
        $meterProvider = MeterProvider::builder()
            ->addReader($this->metricReader)
            ->build();

        Globals::registerInitializer(fn (Configurator $configurator) => $configurator
            ->withTracerProvider($tracerProvider)
            ->withLoggerProvider($loggerProvider)
            ->withMeterProvider($meterProvider)
            ->withPropagator(TraceContextPropagator::getInstance()));
    }

    protected function tearDownOTel(): void
    {
        Globals::reset();
    }

    /**
     * Force collection of pending metrics and return them indexed by instrument name.
     *
     * @return array<string, Metric>
     */
    protected function collectMetrics(): array
    {
        $this->metricReader->collect();

        $byName = [];
        foreach ($this->metricExporter->collect(true) as $metric) {
            $byName[$metric->name] = $metric;
        }

        return $byName;
    }
}
