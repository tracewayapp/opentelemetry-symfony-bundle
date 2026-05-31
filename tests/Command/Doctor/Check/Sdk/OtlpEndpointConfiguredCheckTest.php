<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Sdk;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk\OtlpEndpointConfiguredCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class OtlpEndpointConfiguredCheckTest extends TestCase
{
    public function testErrorWhenNeitherEndpointSet(): void
    {
        $result = (new OtlpEndpointConfiguredCheck())->run(CheckTestHelper::context([]));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('no endpoint', $result->message);
    }

    public function testOkWhenGenericEndpointSet(): void
    {
        $result = (new OtlpEndpointConfiguredCheck())->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otlp.example.com:4318',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('OTEL_EXPORTER_OTLP_ENDPOINT', $result->details['source']);
        self::assertSame('https://otlp.example.com:4318', $result->details['endpoint']);
    }

    public function testSignalSpecificEndpointOverridesGeneric(): void
    {
        $result = (new OtlpEndpointConfiguredCheck())->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otlp.example.com:4318',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => 'https://otlp-traces.example.com:4318',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', $result->details['source']);
        self::assertSame('https://otlp-traces.example.com:4318', $result->details['endpoint']);
    }

    public function testSkippedWhenExporterIsNotOtlp(): void
    {
        $result = (new OtlpEndpointConfiguredCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_EXPORTER' => 'console',
        ]));

        self::assertSame(Status::Skipped, $result->status);
    }
}
