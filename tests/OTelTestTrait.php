<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

trait OTelTestTrait
{
    protected InMemoryExporter $exporter;

    protected function setUpOTel(): void
    {
        Globals::reset();
        $this->exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        Globals::registerInitializer(fn (Configurator $configurator) => $configurator
            ->withTracerProvider($tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance()));
    }

    protected function tearDownOTel(): void
    {
        Globals::reset();
    }
}
