<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Traceway\OpenTelemetryBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    public function testDefaults(): void
    {
        $config = $this->process([]);

        // traces
        self::assertTrue($config['traces']['enabled']);
        self::assertSame('w3c', $config['traces']['propagator']);
        self::assertSame('default', $config['traces']['id_generator']);
        self::assertSame('opentelemetry-symfony', $config['traces']['tracer_name']);
        self::assertSame([], $config['traces']['excluded_paths']);
        self::assertTrue($config['traces']['record_client_ip']);
        self::assertSame(500, $config['traces']['error_status_threshold']);
        self::assertSame(0, $config['traces']['record_exception_min_status']);
        self::assertTrue($config['traces']['console']['enabled']);
        self::assertSame(['messenger:consume', 'messenger:consume-messages'], $config['traces']['console']['excluded_commands']);
        self::assertTrue($config['traces']['http_client']['enabled']);
        self::assertSame([], $config['traces']['http_client']['excluded_hosts']);
        self::assertTrue($config['traces']['messenger']['enabled']);
        self::assertFalse($config['traces']['messenger']['root_spans']);
        self::assertTrue($config['traces']['doctrine']['enabled']);
        self::assertFalse($config['traces']['doctrine']['record_statements']);
        self::assertTrue($config['traces']['doctrine']['only_with_parent']);
        self::assertTrue($config['traces']['cache']['enabled']);
        self::assertSame([], $config['traces']['cache']['excluded_pools']);
        self::assertTrue($config['traces']['twig']['enabled']);
        self::assertSame([], $config['traces']['twig']['excluded_templates']);
        self::assertTrue($config['traces']['scheduler']['enabled']);
        self::assertTrue($config['traces']['mailer']['enabled']);
        self::assertFalse($config['traces']['mailer']['record_subject']);

        // logs
        self::assertTrue($config['logs']['correlation']['enabled']);
        self::assertFalse($config['logs']['export']['enabled']);
        self::assertSame('debug', $config['logs']['export']['level']);
        self::assertFalse($config['logs']['export']['capture_code_attributes']);
        // Flipped from false in v2.0 — cross-ecosystem norm.
        self::assertTrue($config['logs']['export']['unprefixed_attributes']);
        self::assertSame([], $config['logs']['export']['excluded_http_codes']);

        // sdk
        self::assertFalse($config['sdk']['enabled']);
        self::assertFalse($config['sdk']['autoload_enabled']);
        self::assertFalse($config['sdk']['use_putenv']);
        self::assertSame([], $config['sdk']['resource_attributes']);
        self::assertSame([], $config['sdk']['exporter_otlp_headers']);
    }

    public function testCustomValues(): void
    {
        $config = $this->process([
            [
                'traces' => [
                    'enabled' => false,
                    'tracer_name' => 'my-app',
                    'excluded_paths' => ['/health', '/_profiler'],
                    'record_client_ip' => false,
                    'error_status_threshold' => 503,
                    'record_exception_min_status' => 500,
                    'console' => [
                        'enabled' => false,
                        'excluded_commands' => ['cache:clear', 'assets:install'],
                    ],
                    'http_client' => [
                        'enabled' => false,
                        'excluded_hosts' => ['collector.local'],
                    ],
                    'messenger' => [
                        'enabled' => false,
                        'root_spans' => true,
                    ],
                    'doctrine' => [
                        'enabled' => false,
                        'record_statements' => true,
                        'only_with_parent' => false,
                    ],
                    'cache' => [
                        'enabled' => false,
                        'excluded_pools' => ['cache.system', 'cache.validator'],
                    ],
                    'twig' => [
                        'enabled' => false,
                        'excluded_templates' => ['@WebProfiler/', '@Debug/'],
                    ],
                    'scheduler' => ['enabled' => false],
                    'mailer' => [
                        'enabled' => false,
                        'record_subject' => true,
                    ],
                ],
                'logs' => [
                    'correlation' => ['enabled' => false],
                    'export' => [
                        'enabled' => true,
                        'level' => 'warning',
                        'capture_code_attributes' => true,
                        'unprefixed_attributes' => false,
                        'excluded_http_codes' => [404, 405],
                    ],
                ],
            ],
        ]);

        self::assertFalse($config['traces']['enabled']);
        self::assertSame('my-app', $config['traces']['tracer_name']);
        self::assertSame(['/health', '/_profiler'], $config['traces']['excluded_paths']);
        self::assertFalse($config['traces']['record_client_ip']);
        self::assertSame(503, $config['traces']['error_status_threshold']);
        self::assertSame(500, $config['traces']['record_exception_min_status']);
        self::assertFalse($config['traces']['console']['enabled']);
        self::assertSame(['cache:clear', 'assets:install'], $config['traces']['console']['excluded_commands']);
        self::assertFalse($config['traces']['http_client']['enabled']);
        self::assertSame(['collector.local'], $config['traces']['http_client']['excluded_hosts']);
        self::assertFalse($config['traces']['messenger']['enabled']);
        self::assertTrue($config['traces']['messenger']['root_spans']);
        self::assertFalse($config['traces']['doctrine']['enabled']);
        self::assertTrue($config['traces']['doctrine']['record_statements']);
        self::assertFalse($config['traces']['doctrine']['only_with_parent']);
        self::assertFalse($config['traces']['cache']['enabled']);
        self::assertSame(['cache.system', 'cache.validator'], $config['traces']['cache']['excluded_pools']);
        self::assertFalse($config['traces']['twig']['enabled']);
        self::assertSame(['@WebProfiler/', '@Debug/'], $config['traces']['twig']['excluded_templates']);
        self::assertFalse($config['traces']['scheduler']['enabled']);
        self::assertFalse($config['traces']['mailer']['enabled']);
        self::assertTrue($config['traces']['mailer']['record_subject']);

        self::assertFalse($config['logs']['correlation']['enabled']);
        self::assertTrue($config['logs']['export']['enabled']);
        self::assertSame('warning', $config['logs']['export']['level']);
        self::assertTrue($config['logs']['export']['capture_code_attributes']);
        self::assertFalse($config['logs']['export']['unprefixed_attributes']);
        self::assertSame([404, 405], $config['logs']['export']['excluded_http_codes']);
    }

    public function testExcludedPathsNormalization(): void
    {
        $config = $this->process([
            ['traces' => ['excluded_paths' => ['health', '/metrics', '_profiler']]],
        ]);

        self::assertSame(['/health', '/metrics', '/_profiler'], $config['traces']['excluded_paths']);
    }

    public function testTracerNameCannotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['traces' => ['tracer_name' => '']]]);
    }

    #[DataProvider('validPropagatorProvider')]
    public function testValidPropagatorValues(string $value): void
    {
        $config = $this->process([['traces' => ['propagator' => $value]]]);

        self::assertSame($value, $config['traces']['propagator']);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function validPropagatorProvider(): \Generator
    {
        yield 'w3c' => ['w3c'];
        yield 'xray' => ['xray'];
        yield 'w3c+xray' => ['w3c+xray'];
    }

    public function testInvalidPropagatorThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([['traces' => ['propagator' => 'jaeger']]]);
    }

    #[DataProvider('validIdGeneratorProvider')]
    public function testValidIdGeneratorValues(string $value): void
    {
        $config = $this->process([['traces' => ['id_generator' => $value]]]);

        self::assertSame($value, $config['traces']['id_generator']);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function validIdGeneratorProvider(): \Generator
    {
        yield 'default' => ['default'];
        yield 'xray' => ['xray'];
    }

    public function testInvalidIdGeneratorThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([['traces' => ['id_generator' => 'random']]]);
    }

    public function testErrorStatusThresholdBounds(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['traces' => ['error_status_threshold' => 399]]]);
    }

    public function testErrorStatusThresholdUpperBound(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['traces' => ['error_status_threshold' => 600]]]);
    }

    public function testRecordExceptionMinStatusZeroDisablesFiltering(): void
    {
        $config = $this->process([['traces' => ['record_exception_min_status' => 0]]]);

        self::assertSame(0, $config['traces']['record_exception_min_status']);
    }

    public function testRecordExceptionMinStatusBelowHttpRangeThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['traces' => ['record_exception_min_status' => 50]]]);
    }

    public function testRecordExceptionMinStatusAboveHttpRangeThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['traces' => ['record_exception_min_status' => 600]]]);
    }

    public function testLogExportExcludedHttpCodesBelowRangeThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['logs' => ['export' => ['excluded_http_codes' => [42]]]]]);
    }

    public function testLogExportExcludedHttpCodesAboveRangeThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['logs' => ['export' => ['excluded_http_codes' => [600]]]]]);
    }

    public function testLogExportLevelEnumValidated(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['logs' => ['export' => ['level' => 'verbose']]]]);
    }

    /**
     * canBeDisabled() on traces sub-nodes enables the `subsystem: false` shorthand
     * (Symfony's standard pattern, see FrameworkBundle.csrf_protection).
     */
    public function testTracesSubsystemShorthandFalse(): void
    {
        $config = $this->process([
            ['traces' => ['console' => false, 'doctrine' => false]],
        ]);

        self::assertFalse($config['traces']['console']['enabled']);
        self::assertFalse($config['traces']['doctrine']['enabled']);
        self::assertTrue($config['traces']['cache']['enabled'], 'untouched subsystem keeps default');
        // Subsystem defaults still fill in even when shorthand is used
        self::assertSame(['messenger:consume', 'messenger:consume-messages'], $config['traces']['console']['excluded_commands']);
    }

    /**
     * canBeEnabled() on metrics sub-nodes enables the `subsystem: true` shorthand.
     */
    public function testMetricsSubsystemShorthandTrue(): void
    {
        $config = $this->process([
            ['metrics' => ['enabled' => true, 'doctrine' => true]],
        ]);

        self::assertTrue($config['metrics']['doctrine']['enabled']);
        self::assertFalse($config['metrics']['mailer']['enabled'], 'untouched subsystem keeps default-off');
    }

    public function testHttpServerExcludedPathsAreNormalized(): void
    {
        $config = $this->process([[
            'metrics' => [
                'enabled' => true,
                'http_server' => [
                    'enabled' => true,
                    'excluded_paths' => ['health', '/_profiler', 42, '_wdt'],
                ],
            ],
        ]]);

        self::assertSame(
            ['/health', '/_profiler', '/42', '/_wdt'],
            $config['metrics']['http_server']['excluded_paths'],
            'numeric entries are cast to path prefixes instead of silently dropped',
        );
    }

    public function testHttpServerExcludedPathsDefaultsToEmpty(): void
    {
        $config = $this->process([[
            'metrics' => ['enabled' => true],
        ]]);

        self::assertSame([], $config['metrics']['http_server']['excluded_paths']);
    }

    public function testSdkConfigCanBeSupplied(): void
    {
        $expected = [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => true,
            'resource_attributes' => ['service.version' => '1.0', 'deployment.environment' => 'dev'],
            'exporter_otlp_headers' => ['other-config-value' => 'abc', 'Authorization' => 'api-key'],
        ];

        $config = $this->process([['sdk' => $expected]]);

        self::assertSame($expected, $config['sdk']);
    }

    public function testSdkHasUntouchedDefaultValuesIfEnabled(): void
    {
        $config = $this->process([['sdk' => ['enabled' => true]]]);

        self::assertTrue($config['sdk']['enabled']);
        self::assertFalse($config['sdk']['autoload_enabled']);
        self::assertFalse($config['sdk']['use_putenv']);
        self::assertSame([], $config['sdk']['resource_attributes']);
        self::assertSame([], $config['sdk']['exporter_otlp_headers']);
    }

    public function testSdkCanImplicitlyBeEnabled(): void
    {
        $config = $this->process([['sdk' => ['autoload_enabled' => true]]]);

        self::assertTrue($config['sdk']['enabled']);
        self::assertTrue($config['sdk']['autoload_enabled']);
    }

    public function testSdkCanExplicitlyBeDisabled(): void
    {
        $config = $this->process([['sdk' => ['enabled' => false, 'autoload_enabled' => true]]]);

        self::assertFalse($config['sdk']['enabled']);
        self::assertTrue($config['sdk']['autoload_enabled']);
    }

    #[DataProvider('metricsSubsystemProvider')]
    public function testMetricsSubsystemRequiresMetricsEnabled(string $subsystem): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(\sprintf(
            '"open_telemetry.metrics.%s.enabled" requires "open_telemetry.metrics.enabled" to be true.',
            $subsystem,
        ));

        $this->process([[
            'metrics' => [
                'enabled' => false,
                $subsystem => ['enabled' => true],
            ],
        ]]);
    }

    #[DataProvider('metricsSubsystemProvider')]
    public function testMetricsSubsystemCanBeEnabledWhenMetricsEnabled(string $subsystem): void
    {
        $config = $this->process([[
            'metrics' => [
                'enabled' => true,
                $subsystem => ['enabled' => true],
            ],
        ]]);

        self::assertTrue($config['metrics'][$subsystem]['enabled']);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function metricsSubsystemProvider(): \Generator
    {
        yield 'messenger' => ['messenger'];
        yield 'doctrine' => ['doctrine'];
        yield 'http_server' => ['http_server'];
        yield 'http_client' => ['http_client'];
        yield 'mailer' => ['mailer'];
    }

    public function testEmptyExcludedPathEntriesAreDropped(): void
    {
        $config = $this->process([['traces' => ['excluded_paths' => ['', '   ', '/health', 'metrics']]]]);

        self::assertSame(['/health', '/metrics'], $config['traces']['excluded_paths']);
    }

    public function testEmptyMetricsExcludedPathEntriesAreDropped(): void
    {
        $config = $this->process([['metrics' => ['enabled' => true, 'http_server' => ['excluded_paths' => ['', '/health']]]]]);

        self::assertSame(['/health'], $config['metrics']['http_server']['excluded_paths']);
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return $this->processor->processConfiguration($this->configuration, $configs);
    }
}
