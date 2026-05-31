<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Bundle;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle\OtelLogHandlerCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class OtelLogHandlerCheckTest extends TestCase
{
    public function testSkippedWhenDisabled(): void
    {
        $result = (new OtelLogHandlerCheck())->run(CheckTestHelper::context(
            params: ['open_telemetry.logs.export.enabled' => false],
        ));

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testWarningWhenEnabledButLoggerProviderIsNoop(): void
    {
        $result = (new OtelLogHandlerCheck())->run(CheckTestHelper::context(
            params: ['open_telemetry.logs.export.enabled' => true],
        ));

        self::assertSame(Status::Warning, $result->status);
        self::assertStringContainsString('Noop', $result->message);
        self::assertNotNull($result->remediation);
    }

    public function testOkWhenEnabledAndRealLoggerProvider(): void
    {
        $real = $this->realLoggerProvider();
        $result = (new OtelLogHandlerCheck())->run(CheckTestHelper::context(
            params: ['open_telemetry.logs.export.enabled' => true],
            logger: $real,
        ));

        self::assertSame(Status::Ok, $result->status);
    }

    private function realLoggerProvider(): LoggerProviderInterface
    {
        return new class implements LoggerProviderInterface {
            public function getLogger(
                string $name,
                ?string $version = null,
                ?string $schemaUrl = null,
                iterable $attributes = [],
            ): LoggerInterface {
                return new \OpenTelemetry\API\Logs\NoopLogger();
            }
        };
    }
}
