<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Metrics;

/**
 * Shared explicit bucket boundaries for OpenTelemetry duration histograms.
 *
 * Centralizing the boundaries here keeps every Metered* class in the bundle
 * reporting the same bucket layout, so cross-subsystem latency comparisons in
 * backends (Grafana, Tempo, etc.) stay coherent and aggregate well.
 *
 * Values match the boundaries recommended by the OTel HTTP and messaging
 * metric semantic conventions for second-based latency histograms.
 */
final class DurationBoundaries
{
    /**
     * Bucket boundaries (in seconds) for HTTP and messaging latency
     * histograms, matching the OTel HTTP/messaging semconv advisory.
     *
     * @var list<float|int>
     */
    public const SECONDS = [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10,
    ];

    /**
     * Bucket boundaries for db.client.operation.duration per the OTel database
     * semconv advisory — includes the 1ms bucket sub-millisecond queries need.
     *
     * @var list<float|int>
     */
    public const DB_SECONDS = [
        0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10,
    ];

    private function __construct()
    {
    }
}
