<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support;

use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\NetworkCheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Severity;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckRunner;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\EnvReaderInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\GlobalsAccessorInterface;

final class CheckRunnerTest extends TestCase
{
    public function testRunsAllChecksWhenOnlyIsEmpty(): void
    {
        $runner = $this->makeRunner([
            $this->okCheck('alpha'),
            $this->okCheck('beta'),
        ]);

        $report = $runner->run(skipNetwork: false);

        self::assertCount(2, $report->checks);
        self::assertSame('alpha', $report->checks[0]->name);
        self::assertSame('beta', $report->checks[1]->name);
    }

    public function testOnlyFilterRestrictsToNamedChecks(): void
    {
        $runner = $this->makeRunner([
            $this->okCheck('alpha'),
            $this->okCheck('beta'),
            $this->okCheck('gamma'),
        ]);

        $report = $runner->run(skipNetwork: false, only: ['alpha', 'gamma']);

        self::assertCount(2, $report->checks);
        $names = array_map(static fn ($c) => $c->name, $report->checks);
        self::assertSame(['alpha', 'gamma'], $names);
    }

    public function testNetworkChecksAreSkippedWhenSkipNetworkIsTrue(): void
    {
        $runner = $this->makeRunner([
            $this->okCheck('regular'),
            $this->networkCheck('reachability'),
        ]);

        $report = $runner->run(skipNetwork: true);

        self::assertCount(2, $report->checks);
        $reachability = $this->findByName($report, 'reachability');
        self::assertSame(Status::Skipped, $reachability->result->status);
        self::assertSame('skipped by --skip-network', $reachability->result->message);
    }

    public function testNetworkChecksRunWhenSkipNetworkIsFalse(): void
    {
        $runner = $this->makeRunner([
            $this->networkCheck('reachability'),
        ]);

        $report = $runner->run(skipNetwork: false);

        self::assertCount(1, $report->checks);
        self::assertSame(Status::Ok, $report->checks[0]->result->status);
    }

    public function testThrowingCheckBecomesErrorResult(): void
    {
        $runner = $this->makeRunner([
            $this->throwingCheck('explodes', new \RuntimeException('boom')),
            $this->okCheck('survives'),
        ]);

        $report = $runner->run(skipNetwork: false);

        self::assertCount(2, $report->checks);
        $explodes = $this->findByName($report, 'explodes');
        self::assertSame(Status::Error, $explodes->result->status);
        self::assertStringContainsString('RuntimeException', $explodes->result->message);
        self::assertStringContainsString('boom', $explodes->result->message);

        $survives = $this->findByName($report, 'survives');
        self::assertSame(Status::Ok, $survives->result->status);
    }

    public function testHasFailureAtOrAboveErrorThreshold(): void
    {
        $runner = $this->makeRunner([
            $this->resultCheck('warn', CheckResult::warning('warn', 'a warning')),
            $this->resultCheck('err', CheckResult::error('err', 'an error')),
        ]);

        $report = $runner->run(skipNetwork: false);

        self::assertTrue($report->hasFailureAtOrAbove(Severity::Error));
        self::assertTrue($report->hasFailureAtOrAbove(Severity::Warning));
    }

    public function testHasFailureAtOrAboveIgnoresSkippedAndInfo(): void
    {
        $runner = $this->makeRunner([
            $this->resultCheck('info', CheckResult::info('info', 'just info')),
            $this->resultCheck('skip', CheckResult::skipped('skip', 'not applicable')),
        ]);

        $report = $runner->run(skipNetwork: false);

        self::assertFalse($report->hasFailureAtOrAbove(Severity::Warning));
        self::assertFalse($report->hasFailureAtOrAbove(Severity::Error));
    }

    public function testCountsSummary(): void
    {
        $runner = $this->makeRunner([
            $this->resultCheck('a', CheckResult::ok('a', 'ok')),
            $this->resultCheck('b', CheckResult::ok('b', 'ok')),
            $this->resultCheck('c', CheckResult::warning('c', 'warn')),
            $this->resultCheck('d', CheckResult::error('d', 'err')),
            $this->resultCheck('e', CheckResult::info('e', 'info')),
            $this->resultCheck('f', CheckResult::skipped('f', 'skip')),
        ]);

        $report = $runner->run(skipNetwork: false);
        $counts = $report->counts();

        self::assertSame(2, $counts['ok']);
        self::assertSame(1, $counts['warning']);
        self::assertSame(1, $counts['error']);
        self::assertSame(1, $counts['info']);
        self::assertSame(1, $counts['skipped']);
    }

    /**
     * @param list<CheckInterface> $checks
     */
    private function makeRunner(array $checks): CheckRunner
    {
        return new CheckRunner(
            $checks,
            $this->globalsStub(),
            $this->envStub([]),
            new ParameterBag(),
        );
    }

    private function findByName(\Traceway\OpenTelemetryBundle\Command\Doctor\Support\RunReport $report, string $name): \Traceway\OpenTelemetryBundle\Command\Doctor\Support\CompletedCheck
    {
        foreach ($report->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }
        self::fail(sprintf('No check named "%s" in report', $name));
    }

    private function okCheck(string $name): CheckInterface
    {
        return $this->resultCheck($name, CheckResult::ok($name, sprintf('%s passed', $name)));
    }

    private function networkCheck(string $name): NetworkCheckInterface
    {
        return new class($name) implements NetworkCheckInterface {
            public function __construct(private readonly string $name) {}

            public function name(): string { return $this->name; }
            public function label(): string { return $this->name; }
            public function group(): CheckGroup { return CheckGroup::Connectivity; }
            public function run(CheckContext $context): CheckResult
            {
                return CheckResult::ok($this->name, 'reachable');
            }
        };
    }

    private function throwingCheck(string $name, \Throwable $error): CheckInterface
    {
        return new class($name, $error) implements CheckInterface {
            public function __construct(private readonly string $name, private readonly \Throwable $error) {}

            public function name(): string { return $this->name; }
            public function label(): string { return $this->name; }
            public function group(): CheckGroup { return CheckGroup::Runtime; }
            public function run(CheckContext $context): CheckResult { throw $this->error; }
        };
    }

    private function resultCheck(string $name, CheckResult $result): CheckInterface
    {
        return new class($name, $result) implements CheckInterface {
            public function __construct(private readonly string $name, private readonly CheckResult $result) {}

            public function name(): string { return $this->name; }
            public function label(): string { return $this->name; }
            public function group(): CheckGroup { return CheckGroup::Runtime; }
            public function run(CheckContext $context): CheckResult { return $this->result; }
        };
    }

    private function globalsStub(): GlobalsAccessorInterface
    {
        return new class implements GlobalsAccessorInterface {
            public function tracerProvider(): TracerProviderInterface { return new NoopTracerProvider(); }
            public function meterProvider(): MeterProviderInterface { return new NoopMeterProvider(); }
            public function loggerProvider(): LoggerProviderInterface { return new NoopLoggerProvider(); }
            public function propagator(): TextMapPropagatorInterface { return NoopTextMapPropagator::getInstance(); }
        };
    }

    /**
     * @param array<string, string> $values
     */
    private function envStub(array $values): EnvReaderInterface
    {
        return new class($values) implements EnvReaderInterface {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values) {}

            public function get(string $name): ?string { return $this->values[$name] ?? null; }
            public function has(string $name): bool { return isset($this->values[$name]); }
        };
    }
}
