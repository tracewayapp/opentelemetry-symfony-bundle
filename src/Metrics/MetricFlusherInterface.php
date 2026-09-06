<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Metrics;

/**
 * Hands the accumulated measurements to the configured metric readers.
 *
 * Traces and logs are pushed by their batch processors, which reconsider their
 * schedule inside {@see \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor::onEnd()}
 * and {@see \OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor::onEmit()}
 * every time an item is produced. Metrics have no such moment: a data point is
 * an update to a running aggregation, not an event that ends, so collection has
 * to be driven from the outside.
 */
interface MetricFlusherInterface
{
    /**
     * Exports the pending measurements, unless this is the first call in the
     * process or the configured interval has not elapsed yet.
     *
     * Never throws: telemetry must not break a request that already succeeded.
     *
     * @return bool true when measurements were handed to the readers. False
     *              covers every other outcome — the first call in the process,
     *              a call inside the interval, no SDK meter provider, or an
     *              export that failed — because a caller can act on none of
     *              them. It is a signal for tests and diagnostics, not a result
     *              to branch on.
     */
    public function flush(): bool;
}
