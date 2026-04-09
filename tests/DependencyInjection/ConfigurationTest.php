<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
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

        self::assertTrue($config['traces_enabled']);
        self::assertSame('opentelemetry-symfony', $config['tracer_name']);
        self::assertSame([], $config['excluded_paths']);
        self::assertTrue($config['record_client_ip']);
        self::assertSame(500, $config['error_status_threshold']);
        self::assertTrue($config['console_enabled']);
        self::assertSame(['messenger:consume', 'messenger:consume-messages'], $config['console_excluded_commands']);
        self::assertTrue($config['http_client_enabled']);
        self::assertTrue($config['messenger_enabled']);
        self::assertFalse($config['messenger_root_spans']);
        self::assertTrue($config['doctrine_enabled']);
        self::assertTrue($config['doctrine_record_statements']);
        self::assertTrue($config['cache_enabled']);
        self::assertSame([], $config['cache_excluded_pools']);
        self::assertTrue($config['twig_enabled']);
        self::assertSame([], $config['twig_excluded_templates']);
    }

    public function testCustomValues(): void
    {
        $config = $this->process([
            [
                'traces_enabled' => false,
                'tracer_name' => 'my-app',
                'excluded_paths' => ['/health', '/_profiler'],
                'record_client_ip' => false,
                'error_status_threshold' => 400,
                'console_enabled' => false,
                'console_excluded_commands' => ['cache:clear', 'assets:install'],
                'http_client_enabled' => false,
                'messenger_enabled' => false,
                'messenger_root_spans' => true,
                'doctrine_enabled' => false,
                'doctrine_record_statements' => false,
                'cache_enabled' => false,
                'cache_excluded_pools' => ['cache.system', 'cache.validator'],
                'twig_enabled' => false,
                'twig_excluded_templates' => ['@WebProfiler/', '@Debug/'],
            ],
        ]);

        self::assertFalse($config['traces_enabled']);
        self::assertSame('my-app', $config['tracer_name']);
        self::assertSame(['/health', '/_profiler'], $config['excluded_paths']);
        self::assertFalse($config['record_client_ip']);
        self::assertSame(400, $config['error_status_threshold']);
        self::assertFalse($config['console_enabled']);
        self::assertSame(['cache:clear', 'assets:install'], $config['console_excluded_commands']);
        self::assertFalse($config['http_client_enabled']);
        self::assertFalse($config['messenger_enabled']);
        self::assertTrue($config['messenger_root_spans']);
        self::assertFalse($config['doctrine_enabled']);
        self::assertFalse($config['doctrine_record_statements']);
        self::assertFalse($config['cache_enabled']);
        self::assertSame(['cache.system', 'cache.validator'], $config['cache_excluded_pools']);
        self::assertFalse($config['twig_enabled']);
        self::assertSame(['@WebProfiler/', '@Debug/'], $config['twig_excluded_templates']);
    }

    public function testExcludedPathsNormalization(): void
    {
        $config = $this->process([
            ['excluded_paths' => ['health', '/metrics', '_profiler']],
        ]);

        self::assertSame(['/health', '/metrics', '/_profiler'], $config['excluded_paths']);
    }

    public function testTracerNameCannotBeEmpty(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->process([['tracer_name' => '']]);
    }

    public function testErrorStatusThresholdBounds(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->process([['error_status_threshold' => 399]]);
    }

    public function testErrorStatusThresholdUpperBound(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->process([['error_status_threshold' => 600]]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return $this->processor->processConfiguration($this->configuration, $configs);
    }
}
