<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Instrumentation;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

/**
 * Lazy meter resolution shared by metric instrumentation.
 * Using classes must declare a `private readonly string $meterName` property.
 */
trait MeterAwareTrait
{
    private ?MeterInterface $meter = null;

    private function getMeter(): MeterInterface
    {
        return $this->meter ??= Globals::meterProvider()->getMeter(
            $this->meterName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }

    private function resetMeter(): void
    {
        $this->meter = null;
    }
}
