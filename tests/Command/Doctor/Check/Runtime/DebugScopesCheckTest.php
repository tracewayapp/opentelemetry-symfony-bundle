<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Check\Runtime;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime\DebugScopesCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support\CheckTestHelper;

final class DebugScopesCheckTest extends TestCase
{
    public function testOkWhenAssertionsAreCompiledOut(): void
    {
        $result = (new DebugScopesCheck('-1'))->run(CheckTestHelper::context([]));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testOkWhenAssertionsAreOff(): void
    {
        $result = (new DebugScopesCheck('0'))->run(CheckTestHelper::context([]));

        self::assertSame(Status::Ok, $result->status);
    }

    public function testWarnsWhenAssertionsAreOnAndDebugScopesNotDisabled(): void
    {
        $result = (new DebugScopesCheck('1'))->run(CheckTestHelper::context([]));

        self::assertSame(Status::Warning, $result->status);
        self::assertStringContainsString('debug_backtrace', $result->message);
        self::assertNotNull($result->remediation);
    }

    public function testOkWhenAssertionsAreOnButDebugScopesDisabled(): void
    {
        $result = (new DebugScopesCheck('1'))->run(
            CheckTestHelper::context(['OTEL_PHP_DEBUG_SCOPES_DISABLED' => '1']),
        );

        self::assertSame(Status::Ok, $result->status);
    }

    public function testDoesNotWarnInDebugMode(): void
    {
        $result = (new DebugScopesCheck('1'))->run(
            CheckTestHelper::context([], ['kernel.debug' => true]),
        );

        self::assertSame(Status::Ok, $result->status, 'assertions on in dev is correct; warning there would fail --fail-on=warning in CI');
    }

    public function testWarnsWhenDebugIsOff(): void
    {
        $result = (new DebugScopesCheck('1'))->run(
            CheckTestHelper::context([], ['kernel.debug' => false]),
        );

        self::assertSame(Status::Warning, $result->status);
    }

    public function testFalseyEnvValueStillWarns(): void
    {
        $result = (new DebugScopesCheck('1'))->run(
            CheckTestHelper::context(['OTEL_PHP_DEBUG_SCOPES_DISABLED' => 'false']),
        );

        self::assertSame(Status::Warning, $result->status);
    }

    public function testReadsLiveIniValueByDefault(): void
    {
        $result = (new DebugScopesCheck())->run(CheckTestHelper::context([]));

        self::assertNotSame(Status::Skipped, $result->status);
    }
}
