<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Metrics;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Metrics\MetricFlusher;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class MetricFlusherTest extends TestCase
{
    use OTelTestTrait;

    private float $now = 1000.0;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->now = 1000.0;
        // The flusher's state models the process, and the test runner does not
        // start a new one per test.
        MetricFlusher::resetProcessState();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testFirstCallInAProcessIsLeftToTheShutdownHook(): void
    {
        $this->record('probe_total');

        self::assertFalse($this->flusher(60.0)->flush());
        self::assertSame([], $this->exported(), 'The SDK shutdown hook exports on its way out.');
    }

    public function testRequestPerProcessRuntimesNeverExportTwice(): void
    {
        // What PHP-FPM looks like: every request arrives in a process of its
        // own, reaches flush() once, and leaves the export to the shutdown hook.
        foreach (range(1, 5) as $ignored) {
            MetricFlusher::resetProcessState();
            $this->record('probe_total');

            self::assertFalse($this->flusher(60.0)->flush());
        }

        self::assertSame([], $this->exported());
    }

    public function testAReusedProcessStillExportsWhenTheContainerIsRebuilt(): void
    {
        // Kernel::reboot() and the RoadRunner/Swoole setups that rebuild the
        // container hand every request a new flusher inside one long-lived
        // process. Were the skip-once flag scoped to the instance, each request
        // would look like the first and nothing would ever be exported.
        $this->flusher(60.0)->flush();

        $this->record('probe_total');

        self::assertTrue($this->flusher(60.0)->flush());
        self::assertArrayHasKey('probe_total', $this->exported());
    }

    public function testTheThrottleSurvivesARebuiltContainerToo(): void
    {
        // The same reasoning for the window: a fresh instance must not reopen
        // it, or a rebuild-per-request runtime would export on every request.
        $this->flusher(60.0)->flush();
        $this->record('probe_total');
        $this->flusher(60.0)->flush();
        $this->exported();

        $this->now += 59.0;
        $this->record('probe_total');

        self::assertFalse($this->flusher(60.0)->flush());
        self::assertSame([], $this->exported());
    }

    public function testExportsPendingMeasurementsOnceTheProcessIsReused(): void
    {
        $flusher = $this->flusher(60.0);
        $flusher->flush();

        $this->record('probe_total');

        self::assertTrue($flusher->flush());
        self::assertArrayHasKey('probe_total', $this->exported());
    }

    public function testSkipsExportUntilTheIntervalHasElapsed(): void
    {
        $flusher = $this->flusher(60.0);
        $flusher->flush();

        $this->record('probe_total');
        self::assertTrue($flusher->flush());
        $this->exported();

        $this->now += 59.0;
        $this->record('probe_total');

        self::assertFalse($flusher->flush(), 'The second export falls inside the interval.');
        self::assertSame([], $this->exported(), 'Nothing may reach the exporter while throttled.');
    }

    public function testExportsAgainOnceTheIntervalHasElapsed(): void
    {
        $flusher = $this->flusher(60.0);
        $flusher->flush();

        $this->record('probe_total');
        $flusher->flush();
        $this->exported();

        $this->now += 60.0;
        $this->record('probe_total');

        self::assertTrue($flusher->flush());
        self::assertArrayHasKey('probe_total', $this->exported());
    }

    public function testZeroIntervalExportsOnEveryCall(): void
    {
        $flusher = $this->flusher(0.0);
        $flusher->flush();

        $this->record('probe_total');
        self::assertTrue($flusher->flush());
        self::assertTrue($flusher->flush());
    }

    public function testTheIntervalIsClaimedEvenWhenNoProviderIsConfigured(): void
    {
        // A failing or missing provider must not turn every following request
        // into a retry: the window is claimed before the export is attempted.
        Globals::reset();
        $flusher = $this->flusher(60.0);
        $flusher->flush();

        self::assertFalse($flusher->flush());

        $this->setUpOTel();
        $this->record('probe_total');

        self::assertFalse($flusher->flush(), 'Still inside the window claimed by the failed attempt.');
    }

    public function testUnsetIntervalFollowsTheSdkCadence(): void
    {
        // 60 s is the SDK's documented default for OTEL_METRIC_EXPORT_INTERVAL;
        // the bundle reads it rather than restating it.
        $flusher = new MetricFlusher(null, fn (): float => $this->now);
        $flusher->flush();

        $this->record('probe_total');
        self::assertTrue($flusher->flush());
        $this->exported();

        $this->now += 59.0;
        $this->record('probe_total');
        self::assertFalse($flusher->flush());

        $this->now += 1.0;
        self::assertTrue($flusher->flush());
    }

    public function testUnsetIntervalHonoursTheStandardEnvironmentVariable(): void
    {
        $previous = $_SERVER['OTEL_METRIC_EXPORT_INTERVAL'] ?? null;
        $_SERVER['OTEL_METRIC_EXPORT_INTERVAL'] = '10000';

        try {
            $flusher = new MetricFlusher(null, fn (): float => $this->now);
            $flusher->flush();

            $this->record('probe_total');
            self::assertTrue($flusher->flush());
            $this->exported();

            $this->now += 9.0;
            $this->record('probe_total');
            self::assertFalse($flusher->flush(), 'Still inside the 10 s window taken from the environment.');

            $this->now += 1.0;
            self::assertTrue($flusher->flush());
        } finally {
            if (null === $previous) {
                unset($_SERVER['OTEL_METRIC_EXPORT_INTERVAL']);
            } else {
                $_SERVER['OTEL_METRIC_EXPORT_INTERVAL'] = $previous;
            }
        }
    }

    private function flusher(float $interval): MetricFlusher
    {
        return new MetricFlusher($interval, fn (): float => $this->now);
    }

    private function record(string $counter): void
    {
        Globals::meterProvider()->getMeter('test')->createCounter($counter)->add(1);
    }

    /**
     * @return array<string, \OpenTelemetry\SDK\Metrics\Data\Metric>
     */
    private function exported(): array
    {
        $byName = [];
        foreach ($this->metricExporter->collect(true) as $metric) {
            $byName[$metric->name] = $metric;
        }

        return $byName;
    }
}
