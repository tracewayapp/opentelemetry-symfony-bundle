<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Instrumentation;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

/**
 * Lazy tracer resolution that never pins a noop tracer seen before SDK init.
 * Using classes must declare a `private readonly string $tracerName` property.
 */
trait TracerAwareTrait
{
    private ?TracerInterface $tracer = null;

    private function isEnabled(): bool
    {
        return $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        if (null !== $this->tracer) {
            return $this->tracer;
        }

        $tracer = Globals::tracerProvider()->getTracer(
            $this->tracerName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );

        // Only memoize a live tracer, so a noop seen before SDK init isn't pinned.
        if ($tracer->isEnabled()) {
            $this->tracer = $tracer;
        }

        return $tracer;
    }

    private function resetTracer(): void
    {
        $this->tracer = null;
    }
}
