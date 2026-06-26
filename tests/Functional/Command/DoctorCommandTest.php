<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Functional\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Traceway\OpenTelemetryBundle\Command\DoctorCommand;
use Traceway\OpenTelemetryBundle\Tests\Functional\OpenTelemetryTestKernel;

final class DoctorCommandTest extends TestCase
{
    private ?OpenTelemetryTestKernel $kernel = null;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** @var callable|null */
    private mixed $previousExceptionHandler = null;

    private const MANAGED_ENV_VARS = [
        'OTEL_SERVICE_NAME',
        'OTEL_TRACES_EXPORTER',
        'OTEL_LOGS_EXPORTER',
        'OTEL_METRICS_EXPORTER',
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
        'OTEL_EXPORTER_OTLP_PROTOCOL',
        'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL',
        'OTEL_TRACES_SAMPLER',
        'OTEL_TRACES_SAMPLER_ARG',
        'OTEL_RESOURCE_ATTRIBUTES',
        'OTEL_PHP_AUTOLOAD_ENABLED',
    ];

    protected function setUp(): void
    {
        $this->previousExceptionHandler = set_exception_handler(null);
        restore_exception_handler();

        foreach (self::MANAGED_ENV_VARS as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if (false !== $value) {
                putenv("$name=$value");
                $_SERVER[$name] = $value;
                $_ENV[$name] = $value;
            } else {
                putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            }
        }
        $this->envBackup = [];

        if (null !== $this->kernel) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }

        set_exception_handler($this->previousExceptionHandler);
    }

    public function testCommandIsRegisteredWithPrimaryNameAndAlias(): void
    {
        $command = $this->command();

        self::assertSame('traceway:doctor', $command->getName());
        self::assertContains('debug:traceway', $command->getAliases());
    }

    public function testTextFormatRunsAndOutputsHeader(): void
    {
        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([
            '--skip-network' => true,
            '--fail-on' => 'error',
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Traceway Doctor', $output);
        self::assertStringContainsString('Results:', $output);

        self::assertSame(1, $exitCode);
    }

    public function testJsonFormatProducesVersionedEnvelope(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([
            '--format' => 'json',
            '--skip-network' => true,
        ]);

        $output = $tester->getDisplay();
        $decoded = json_decode($output, true);

        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['version']);
        self::assertArrayHasKey('summary', $decoded);
        self::assertArrayHasKey('checks', $decoded);
        self::assertArrayHasKey('exit_code', $decoded['summary']);
    }

    public function testJsonFormatReturnsExitCodeOneWhenServiceNameMissing(): void
    {
        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([
            '--format' => 'json',
            '--skip-network' => true,
        ]);

        $output = $tester->getDisplay();
        $decoded = json_decode($output, true);

        self::assertSame(1, $exitCode);

        $serviceNameCheck = null;
        foreach ($decoded['checks'] as $check) {
            if ('service_name' === $check['name']) {
                $serviceNameCheck = $check;
                break;
            }
        }

        self::assertNotNull($serviceNameCheck, 'service_name check missing from output');
        self::assertSame('error', $serviceNameCheck['status']);
    }

    public function testServiceNameSetMakesServiceCheckOk(): void
    {
        putenv('OTEL_SERVICE_NAME=test-service');
        $_SERVER['OTEL_SERVICE_NAME'] = 'test-service';

        $tester = new CommandTester($this->command());
        $tester->execute([
            '--format' => 'json',
            '--skip-network' => true,
        ]);

        $decoded = json_decode($tester->getDisplay(), true);
        $serviceNameCheck = $this->findCheckByName($decoded, 'service_name');

        self::assertSame('ok', $serviceNameCheck['status']);
        self::assertSame('test-service', $serviceNameCheck['details']['value']);
    }

    public function testOnlyFlagRestrictsToNamedChecks(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([
            '--format' => 'json',
            '--skip-network' => true,
            '--only' => 'service_name,traces_exporter',
        ]);

        $decoded = json_decode($tester->getDisplay(), true);
        $names = array_map(static fn (array $c) => $c['name'], $decoded['checks']);

        self::assertContains('service_name', $names);
        self::assertContains('traces_exporter', $names);
        self::assertNotContains('protobuf_extension', $names);
        self::assertNotContains('xray_dependency', $names);
    }

    public function testInvalidFormatReturnsInvalidExitCode(): void
    {
        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute(['--format' => 'xml']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertStringContainsString('Unknown --format', $tester->getDisplay());
    }

    public function testInvalidFailOnReturnsInvalidExitCode(): void
    {
        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute(['--fail-on' => 'banana']);

        self::assertSame(2, $exitCode);
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private function findCheckByName(array $decoded, string $name): array
    {
        foreach ($decoded['checks'] as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }
        self::fail("Check '$name' not found in output");
    }

    private function command(): DoctorCommand
    {
        $this->kernel = new OpenTelemetryTestKernel();
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $command = $container->get(DoctorCommand::class);
        self::assertInstanceOf(DoctorCommand::class, $command);

        return $command;
    }
}
