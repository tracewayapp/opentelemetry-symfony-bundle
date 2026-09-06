<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Sdk;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk\MetricsTemporalityCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class MetricsTemporalityCheckTest extends TestCase
{
    public function testSkippedWhenMetricsAreDisabled(): void
    {
        $result = (new MetricsTemporalityCheck())->run(CheckTestHelper::context([], []));

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testWarnsWhenCumulativeProducersShareOneSeries(): void
    {
        // The default temporality with no per-process identity: several
        // producers write their own totals to the same series.
        $result = (new MetricsTemporalityCheck())->run($this->context([]));

        self::assertSame(Status::Warning, $result->status);
        self::assertNotNull($result->remediation);
        self::assertStringContainsString('service_instance', (string) $result->remediation);
        self::assertStringContainsString('delta', (string) $result->remediation);
    }

    public function testOkWhenCumulativeProducersAreToldApart(): void
    {
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_PHP_DETECTORS' => 'host,process,service_instance',
        ]));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testOkUnderDeltaWithoutAnyIdentity(): void
    {
        // Summing across producers is what delta is for, so no identity is needed.
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE' => 'delta',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('delta', $result->details['temporality']);
    }

    public function testTemporalityIsReadCaseInsensitively(): void
    {
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE' => 'DELTA',
        ]));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testDetectorListIsMatchedOnWholeEntriesOnly(): void
    {
        // A substring must not pass for the detector name.
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_PHP_DETECTORS' => 'host, process',
        ]));

        self::assertSame(Status::Warning, $result->status);
    }

    public function testOkWhenIdentityIsStatedInResourceAttributes(): void
    {
        // How an orchestrator supplies it: service.instance.id=$POD_NAME. The
        // bundle itself passes sdk.resource_attributes on the same way.
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_RESOURCE_ATTRIBUTES' => 'deployment.environment=prod,service.instance.id=checkout-7f9c',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('OTEL_RESOURCE_ATTRIBUTES', $result->details['source']);
    }

    public function testResourceAttributeWithoutAValueCarriesNoIdentity(): void
    {
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_RESOURCE_ATTRIBUTES' => 'service.instance.id=',
        ]));

        self::assertSame(Status::Warning, $result->status);
    }

    public function testResourceAttributesAreMatchedOnWholeKeysOnly(): void
    {
        // A key that merely ends in the one we want is a different attribute.
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_RESOURCE_ATTRIBUTES' => 'app.service.instance.id=7',
        ]));

        self::assertSame(Status::Warning, $result->status);
    }

    public function testLowMemoryTemporalityIsNotTreatedAsCumulative(): void
    {
        // The PHP exporter maps lowmemory to no preference, which leaves every
        // synchronous instrument — all this bundle records — exporting delta.
        $result = (new MetricsTemporalityCheck())->run($this->context([
            'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE' => 'lowmemory',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('lowmemory', $result->details['temporality']);
    }

    /**
     * @param array<string, string> $env
     */
    private function context(array $env): \Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext
    {
        return CheckTestHelper::context($env, ['open_telemetry.metrics.enabled' => true]);
    }
}
