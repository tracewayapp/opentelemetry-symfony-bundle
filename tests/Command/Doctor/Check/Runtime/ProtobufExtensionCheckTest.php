<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Runtime;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime\ProtobufExtensionCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class ProtobufExtensionCheckTest extends TestCase
{
    public function testSkippedWhenProtocolIsHttpJson(): void
    {
        $result = (new ProtobufExtensionCheck())->run(
            CheckTestHelper::context(['OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/json']),
        );

        self::assertSame(Status::Skipped, $result->status);
        self::assertStringContainsString('http/json', $result->message);
    }

    public function testStatusReflectsExtensionPresenceForProtobufProtocol(): void
    {
        $result = (new ProtobufExtensionCheck())->run(
            CheckTestHelper::context(['OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/protobuf']),
        );

        if (\extension_loaded('protobuf')) {
            self::assertSame(Status::Ok, $result->status);
        } else {
            self::assertSame(Status::Warning, $result->status);
            self::assertNotNull($result->remediation);
        }
    }

    public function testDefaultsToHttpProtobufWhenUnset(): void
    {
        $result = (new ProtobufExtensionCheck())->run(CheckTestHelper::context([]));

        self::assertNotSame(Status::Skipped, $result->status);
    }

    public function testSignalSpecificProtocolOverridesGeneric(): void
    {
        $result = (new ProtobufExtensionCheck())->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/protobuf',
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL' => 'http/json',
        ]));

        self::assertSame(Status::Skipped, $result->status);
    }
}
