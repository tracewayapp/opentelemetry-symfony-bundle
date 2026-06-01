<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Bundle;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle\XRayDependencyCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class XRayDependencyCheckTest extends TestCase
{
    public function testSkippedWhenW3cAndDefault(): void
    {
        $result = (new XRayDependencyCheck())->run(CheckTestHelper::context(
            params: [
                'open_telemetry.traces.propagator' => 'w3c',
                'open_telemetry.traces.id_generator' => 'default',
            ],
        ));

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testOkWhenXrayPropagatorAndDepInstalled(): void
    {
        $result = (new XRayDependencyCheck())->run(CheckTestHelper::context(
            params: [
                'open_telemetry.traces.propagator' => 'xray',
                'open_telemetry.traces.id_generator' => 'default',
            ],
        ));

        self::assertSame(Status::Ok, $result->status);
        self::assertStringContainsString('xray', $result->message);
    }

    public function testOkWhenXrayIdGeneratorAndDepInstalled(): void
    {
        $result = (new XRayDependencyCheck())->run(CheckTestHelper::context(
            params: [
                'open_telemetry.traces.propagator' => 'w3c',
                'open_telemetry.traces.id_generator' => 'xray',
            ],
        ));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testOkWhenDualW3cXray(): void
    {
        $result = (new XRayDependencyCheck())->run(CheckTestHelper::context(
            params: [
                'open_telemetry.traces.propagator' => 'w3c+xray',
                'open_telemetry.traces.id_generator' => 'default',
            ],
        ));

        self::assertSame(Status::Ok, $result->status);
    }
}
