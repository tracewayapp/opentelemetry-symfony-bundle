<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Sdk;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk\TracesSamplerCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class TracesSamplerCheckTest extends TestCase
{
    public function testOkWhenUnset(): void
    {
        $result = (new TracesSamplerCheck())->run(CheckTestHelper::context([]));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testOkForKnownSampler(): void
    {
        $result = (new TracesSamplerCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_SAMPLER' => 'parentbased_traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '0.1',
        ]));

        self::assertSame(Status::Ok, $result->status);
        self::assertStringContainsString('0.1', $result->message);
    }

    public function testWarningOnAlwaysOff(): void
    {
        $result = (new TracesSamplerCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_SAMPLER' => 'always_off',
        ]));

        self::assertSame(Status::Warning, $result->status);
        self::assertStringContainsString('no spans', $result->message);
    }

    public function testWarningOnParentbasedAlwaysOff(): void
    {
        $result = (new TracesSamplerCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_SAMPLER' => 'parentbased_always_off',
        ]));

        self::assertSame(Status::Warning, $result->status);
    }

    public function testErrorOnUnknownSampler(): void
    {
        $result = (new TracesSamplerCheck())->run(CheckTestHelper::context([
            'OTEL_TRACES_SAMPLER' => 'magic_8_ball',
        ]));

        self::assertSame(Status::Error, $result->status);
        self::assertStringContainsString('magic_8_ball', $result->message);
    }
}
