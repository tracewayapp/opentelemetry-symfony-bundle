<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Sdk;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk\TracerProviderCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class TracerProviderCheckTest extends TestCase
{
    public function testErrorWhenNoopAndExporterNotNone(): void
    {
        $result = (new TracerProviderCheck())->run(CheckTestHelper::context(
            env: [],
            tracer: new NoopTracerProvider(),
        ));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('Noop', $result->message);
        self::assertNotNull($result->remediation);
    }

    public function testInfoWhenNoopButExporterIsNone(): void
    {
        $result = (new TracerProviderCheck())->run(CheckTestHelper::context(
            env: ['OTEL_TRACES_EXPORTER' => 'none'],
            tracer: new NoopTracerProvider(),
        ));

        self::assertSame(Status::Info, $result->status);
    }

    public function testOkWhenRealProvider(): void
    {
        $real = $this->realLookingProvider();
        $result = (new TracerProviderCheck())->run(CheckTestHelper::context(tracer: $real));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testInfoWhenRealProviderButExporterNone(): void
    {
        $real = $this->realLookingProvider();
        $result = (new TracerProviderCheck())->run(CheckTestHelper::context(
            env: ['OTEL_TRACES_EXPORTER' => 'none'],
            tracer: $real,
        ));

        self::assertSame(Status::Info, $result->status);
    }

    private function realLookingProvider(): TracerProviderInterface
    {
        return new class implements TracerProviderInterface {
            public function getTracer(
                string $name,
                ?string $version = null,
                ?string $schemaUrl = null,
                iterable $attributes = [],
            ): \OpenTelemetry\API\Trace\TracerInterface {
                return new \OpenTelemetry\API\Trace\NoopTracer();
            }
        };
    }
}
