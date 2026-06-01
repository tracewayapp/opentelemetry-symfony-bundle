<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Bundle;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle\MessengerMiddlewareCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class MessengerMiddlewareCheckTest extends TestCase
{
    public function testSkippedWhenDisabled(): void
    {
        $result = (new MessengerMiddlewareCheck())->run(CheckTestHelper::context(
            params: ['open_telemetry.traces.messenger.enabled' => false],
        ));

        self::assertSame(Status::Skipped, $result->status);
    }

    public function testOkWhenEnabledAndMessengerInstalled(): void
    {
        $result = (new MessengerMiddlewareCheck())->run(CheckTestHelper::context(
            params: ['open_telemetry.traces.messenger.enabled' => true],
        ));

        self::assertSame(Status::Ok, $result->status);
    }
}
