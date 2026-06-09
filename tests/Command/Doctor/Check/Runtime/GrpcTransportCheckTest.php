<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Runtime;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime\GrpcTransportCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class GrpcTransportCheckTest extends TestCase
{
    public function testSkippedWhenProtocolIsHttpProtobuf(): void
    {
        $result = (new GrpcTransportCheck())->run(
            CheckTestHelper::context(['OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/protobuf']),
        );

        self::assertSame(Status::Skipped, $result->status);
        self::assertStringContainsString('http/protobuf', $result->message);
    }

    public function testSkippedWhenProtocolIsHttpJson(): void
    {
        $result = (new GrpcTransportCheck())->run(
            CheckTestHelper::context(['OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/json']),
        );

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testSkippedWhenProtocolUnset(): void
    {
        $result = (new GrpcTransportCheck())->run(CheckTestHelper::context([]));

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testStatusReflectsTransportPresenceForGrpcProtocol(): void
    {
        $result = (new GrpcTransportCheck())->run(
            CheckTestHelper::context(['OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc']),
        );

        $extLoaded = \extension_loaded('grpc');
        $packageInstalled = class_exists('\\OpenTelemetry\\Contrib\\Grpc\\GrpcTransportFactory');

        if ($extLoaded && $packageInstalled) {
            self::assertSame(Status::Ok, $result->status);
        } else {
            self::assertSame(Status::Warning, $result->status);
            self::assertNotNull($result->remediation);

            if (!$extLoaded) {
                self::assertStringContainsString('ext-grpc', $result->message);
            }
            if (!$packageInstalled) {
                self::assertStringContainsString('open-telemetry/transport-grpc', $result->message);
            }
        }
    }

    public function testSignalSpecificProtocolOverridesGeneric(): void
    {
        $result = (new GrpcTransportCheck())->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc',
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL' => 'http/json',
        ]));

        self::assertSame(Status::Skipped, $result->status);
    }
}
