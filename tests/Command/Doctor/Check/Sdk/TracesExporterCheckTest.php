<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Sdk;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk\TracesExporterCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class TracesExporterCheckTest extends TestCase
{
    public function testDefaultsToOtlpWhenUnset(): void
    {
        $result = (new TracesExporterCheck())->run(CheckTestHelper::context([]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('otlp', $result->details['value']);
    }

    public function testRecognizesKnownExporters(): void
    {
        foreach (['otlp', 'zipkin', 'console', 'memory'] as $exporter) {
            $result = (new TracesExporterCheck())->run(CheckTestHelper::context([
                'OTEL_TRACES_EXPORTER' => $exporter,
            ]));
            self::assertSame(Status::Ok, $result->status, "Expected OK for $exporter");
        }
    }

    public function testNoneIsInfoNotOk(): void
    {
        $result = (new TracesExporterCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_EXPORTER' => 'none',
        ]));

        self::assertSame(Status::Info, $result->status);
        self::assertStringContainsString('not exported', $result->message);
    }

    public function testErrorOnUnknownExporter(): void
    {
        $result = (new TracesExporterCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_EXPORTER' => 'sumologic',
        ]));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('sumologic', $result->message);
        self::assertNotNull($result->remediation);
    }
}
