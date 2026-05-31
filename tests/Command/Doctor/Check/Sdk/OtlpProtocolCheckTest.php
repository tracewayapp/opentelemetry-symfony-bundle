<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Sdk;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk\OtlpProtocolCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class OtlpProtocolCheckTest extends TestCase
{
    public function testOkWhenUnset(): void
    {
        $result = (new OtlpProtocolCheck())->run(CheckTestHelper::context([]));

        self::assertSame(Status::Ok, $result->status);
        self::assertNull($result->details['value']);
    }

    public function testOkForKnownProtocols(): void
    {
        foreach (['http/json', 'http/protobuf', 'grpc'] as $protocol) {
            $result = (new OtlpProtocolCheck())->run(CheckTestHelper::context([
                'OTEL_EXPORTER_OTLP_PROTOCOL' => $protocol,
            ]));
            self::assertSame(Status::Ok, $result->status, "Expected OK for $protocol");
        }
    }

    public function testWarningForUnknownProtocol(): void
    {
        $result = (new OtlpProtocolCheck())->run(CheckTestHelper::context([
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'thrift',
        ]));

        self::assertSame(Status::Warning, $result->status);
        self::assertNotNull($result->remediation);
    }

    public function testSkippedWhenExporterIsNotOtlp(): void
    {
        $result = (new OtlpProtocolCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_EXPORTER' => 'zipkin',
        ]));

        self::assertSame(Status::Skipped, $result->status);
    }
}
