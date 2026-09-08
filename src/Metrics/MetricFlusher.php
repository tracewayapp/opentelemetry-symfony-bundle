<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Metrics;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface as SdkMeterProviderInterface;

/**
 * Exports the global MeterProvider from the application's own lifecycle, for
 * the runtimes where the SDK's shutdown hook is not reached often enough.
 *
 * Two rules keep that safe on every runtime, without asking which one is in use.
 *
 * The first call in a process is a no-op. If the process ends there — every
 * request under PHP-FPM, every one-shot command — the shutdown hook exports on
 * its way out, and flushing first would only send the same cumulative state
 * twice. Only a process that reaches a second call is one the hook will not
 * serve in time, which is precisely what a worker is.
 *
 * After that, exports are spaced by an interval. Each one carries the whole
 * cumulative state — every stream with every histogram bucket — so its size
 * follows the number of attribute combinations the worker has accumulated, not
 * the number of requests since the last export. Exporting per request therefore
 * multiplies volume without carrying more information, and a collector will
 * start refusing data long before the application notices. Skipping an export
 * costs nothing: cumulative instruments carry their totals into the next one.
 */
final class MetricFlusher implements MetricFlusherInterface
{
    /**
     * Whether flush() has been reached before in this process, which is the
     * signal that the process is serving more than one unit of work.
     *
     * Static because it models the process, not the service. A runtime that
     * rebuilds the container between requests — Kernel::reboot(), some
     * RoadRunner and Swoole setups — would otherwise hand every request a
     * fresh instance, make every call look like the first one, and export
     * nothing until the worker is recycled: the very failure this class exists
     * to prevent, and just as silent.
     */
    private static bool $processIsReused = false;

    /**
     * Monotonic reading, in seconds, before which flush() is a no-op. Static
     * for the same reason: a rebuilt container must not reopen the window.
     */
    private static float $nextFlushAt = 0.0;

    private readonly float $intervalSeconds;

    /**
     * @var \Closure(): float
     */
    private readonly \Closure $now;

    /**
     * @param float|null               $intervalSeconds minimum seconds between exports; 0 exports on every call,
     *                                                  null follows OTEL_METRIC_EXPORT_INTERVAL
     * @param (\Closure(): float)|null $now             monotonic time source, in seconds; overridable for tests
     */
    public function __construct(
        ?float $intervalSeconds = null,
        ?\Closure $now = null,
    ) {
        $this->intervalSeconds = $intervalSeconds ?? self::sdkExportInterval();
        // hrtime() rather than microtime(): the interval must not be affected
        // by clock adjustments on the host.
        $this->now = $now ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public function flush(): bool
    {
        if (!self::$processIsReused) {
            self::$processIsReused = true;

            return false;
        }

        $now = ($this->now)();

        if ($now < self::$nextFlushAt) {
            return false;
        }

        // Claimed before the export, not after: a slow or failing export must
        // not let every subsequent request retry it.
        self::$nextFlushAt = $now + $this->intervalSeconds;

        try {
            $provider = Globals::meterProvider();

            if (!$provider instanceof SdkMeterProviderInterface) {
                return false;
            }

            return $provider->forceFlush();
        } catch (\Throwable $e) {
            error_log(\sprintf('MetricFlusher: forceFlush failed: %s', $e->getMessage()));

            return false;
        }
    }

    /**
     * Forgets that this process has flushed before.
     *
     * @internal the state models a process, which a test cannot restart
     */
    public static function resetProcessState(): void
    {
        self::$processIsReused = false;
        self::$nextFlushAt = 0.0;
    }

    /**
     * The cadence the SDK would use if PHP had a way to run a periodic reader:
     * OTEL_METRIC_EXPORT_INTERVAL when set, its documented default otherwise.
     * Reading it here keeps one number in one place, and lets an application
     * already configured through the standard OTel variables stay consistent
     * without repeating itself in YAML.
     */
    private static function sdkExportInterval(): float
    {
        try {
            return Configuration::getInt(Variables::OTEL_METRIC_EXPORT_INTERVAL) / 1000;
        } catch (\Throwable) {
            // A malformed value is the SDK's to complain about, not ours.
            return Defaults::OTEL_METRIC_EXPORT_INTERVAL / 1000;
        }
    }
}
