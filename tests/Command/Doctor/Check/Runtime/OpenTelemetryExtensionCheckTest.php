<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Runtime;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime\OpenTelemetryExtensionCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class OpenTelemetryExtensionCheckTest extends TestCase
{
    public function testStatusReflectsExtensionPresence(): void
    {
        $result = (new OpenTelemetryExtensionCheck())->run(CheckTestHelper::context());

        if (\extension_loaded('opentelemetry')) {
            self::assertSame(Status::Info, $result->status);
            self::assertStringContainsString('contrib_instrumentation_overlap', $result->message);
        } else {
            self::assertSame(Status::Ok, $result->status);
            self::assertStringContainsString('not loaded', $result->message);
        }
    }
}
