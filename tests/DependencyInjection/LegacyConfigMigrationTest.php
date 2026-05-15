<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Traceway\OpenTelemetryBundle\DependencyInjection\Configuration;

/**
 * Verifies that v1.x flat config keys still produce the same processed result
 * as their v2.0 nested equivalents, emit the documented deprecation message,
 * and hard-fail on same-block flat+nested conflicts.
 *
 * Removed in v3.0 along with the BC layer in Configuration::migrateLegacyKeys().
 */
#[Group('legacy')]
final class LegacyConfigMigrationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    /**
     * @param array<string, mixed> $flatInput
     * @param array<string, mixed> $nestedInput
     */
    #[DataProvider('legacyKeyProvider')]
    #[IgnoreDeprecations]
    public function testFlatKeyProducesSameProcessedConfigAsNested(
        string $_oldKey,
        array $flatInput,
        array $nestedInput,
        string $_expectedNewPath,
    ): void {
        $fromFlat = $this->processor->processConfiguration($this->configuration, [$flatInput]);
        $fromNested = $this->processor->processConfiguration($this->configuration, [$nestedInput]);

        self::assertSame($fromNested, $fromFlat);
    }

    /**
     * @param array<string, mixed> $flatInput
     */
    #[DataProvider('legacyKeyProvider')]
    public function testFlatKeyTriggersDeprecation(
        string $oldKey,
        array $flatInput,
        array $_nestedInput,
        string $expectedNewPath,
    ): void {
        $this->expectUserDeprecationMessage(sprintf(
            'Since traceway/opentelemetry-symfony 2.0: Configuring "open_telemetry.%s" is deprecated, use "open_telemetry.%s" instead. The legacy key will be removed in v3.0.',
            $oldKey,
            $expectedNewPath,
        ));

        $this->processor->processConfiguration($this->configuration, [$flatInput]);
    }

    public function testSameBlockFlatAndNestedConflictThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'Cannot set both legacy "open_telemetry.doctrine_enabled" and nested "open_telemetry.traces.doctrine.enabled" in the same configuration block. Use the nested form only.'
        );

        $this->processor->processConfiguration($this->configuration, [
            [
                'doctrine_enabled' => false,
                'traces' => ['doctrine' => ['enabled' => true]],
            ],
        ]);
    }

    public function testRootLevelLegacyAndNestedConflictThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'Cannot set both legacy "open_telemetry.traces_enabled" and nested "open_telemetry.traces.enabled" in the same configuration block.'
        );

        $this->processor->processConfiguration($this->configuration, [
            [
                'traces_enabled' => false,
                'traces' => ['enabled' => true],
            ],
        ]);
    }

    /**
     * Users who explicitly set the legacy false get the SAME false in the
     * nested target — no surprise "default changed" behavior change for them.
     */
    #[IgnoreDeprecations]
    public function testUnprefixedAttributesLegacyFalseSurvives(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['log_export_unprefixed_attributes' => false],
        ]);

        self::assertFalse($config['logs']['export']['unprefixed_attributes']);
    }

    /**
     * Users who did NOT set the legacy key get the new v2.0 default (true).
     * No deprecation is emitted because no legacy key was used.
     */
    public function testUnprefixedAttributesNewDefaultIsTrue(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [[]]);

        self::assertTrue($config['logs']['export']['unprefixed_attributes']);
    }

    /**
     * @return \Generator<string, array{string, array<string, mixed>, array<string, mixed>, string}>
     */
    public static function legacyKeyProvider(): \Generator
    {
        // Trace root-level
        yield 'traces_enabled' => ['traces_enabled', ['traces_enabled' => false], ['traces' => ['enabled' => false]], 'traces.enabled'];
        yield 'tracer_name' => ['tracer_name', ['tracer_name' => 'my-app'], ['traces' => ['tracer_name' => 'my-app']], 'traces.tracer_name'];
        yield 'excluded_paths' => ['excluded_paths', ['excluded_paths' => ['/health']], ['traces' => ['excluded_paths' => ['/health']]], 'traces.excluded_paths'];
        yield 'record_client_ip' => ['record_client_ip', ['record_client_ip' => false], ['traces' => ['record_client_ip' => false]], 'traces.record_client_ip'];
        yield 'error_status_threshold' => ['error_status_threshold', ['error_status_threshold' => 400], ['traces' => ['error_status_threshold' => 400]], 'traces.error_status_threshold'];

        // Trace subsystems
        yield 'console_enabled' => ['console_enabled', ['console_enabled' => false], ['traces' => ['console' => ['enabled' => false]]], 'traces.console.enabled'];
        yield 'console_excluded_commands' => ['console_excluded_commands', ['console_excluded_commands' => ['cache:clear']], ['traces' => ['console' => ['excluded_commands' => ['cache:clear']]]], 'traces.console.excluded_commands'];
        yield 'http_client_enabled' => ['http_client_enabled', ['http_client_enabled' => false], ['traces' => ['http_client' => ['enabled' => false]]], 'traces.http_client.enabled'];
        yield 'http_client_excluded_hosts' => ['http_client_excluded_hosts', ['http_client_excluded_hosts' => ['cdn.example.com']], ['traces' => ['http_client' => ['excluded_hosts' => ['cdn.example.com']]]], 'traces.http_client.excluded_hosts'];
        yield 'messenger_enabled' => ['messenger_enabled', ['messenger_enabled' => false], ['traces' => ['messenger' => ['enabled' => false]]], 'traces.messenger.enabled'];
        yield 'messenger_root_spans' => ['messenger_root_spans', ['messenger_root_spans' => true], ['traces' => ['messenger' => ['root_spans' => true]]], 'traces.messenger.root_spans'];
        yield 'doctrine_enabled' => ['doctrine_enabled', ['doctrine_enabled' => false], ['traces' => ['doctrine' => ['enabled' => false]]], 'traces.doctrine.enabled'];
        yield 'doctrine_record_statements' => ['doctrine_record_statements', ['doctrine_record_statements' => false], ['traces' => ['doctrine' => ['record_statements' => false]]], 'traces.doctrine.record_statements'];
        yield 'cache_enabled' => ['cache_enabled', ['cache_enabled' => false], ['traces' => ['cache' => ['enabled' => false]]], 'traces.cache.enabled'];
        yield 'cache_excluded_pools' => ['cache_excluded_pools', ['cache_excluded_pools' => ['cache.system']], ['traces' => ['cache' => ['excluded_pools' => ['cache.system']]]], 'traces.cache.excluded_pools'];
        yield 'twig_enabled' => ['twig_enabled', ['twig_enabled' => false], ['traces' => ['twig' => ['enabled' => false]]], 'traces.twig.enabled'];
        yield 'twig_excluded_templates' => ['twig_excluded_templates', ['twig_excluded_templates' => ['@WebProfiler/']], ['traces' => ['twig' => ['excluded_templates' => ['@WebProfiler/']]]], 'traces.twig.excluded_templates'];
        yield 'scheduler_enabled' => ['scheduler_enabled', ['scheduler_enabled' => false], ['traces' => ['scheduler' => ['enabled' => false]]], 'traces.scheduler.enabled'];
        yield 'mailer_enabled' => ['mailer_enabled', ['mailer_enabled' => false], ['traces' => ['mailer' => ['enabled' => false]]], 'traces.mailer.enabled'];
        yield 'mailer_record_subject' => ['mailer_record_subject', ['mailer_record_subject' => true], ['traces' => ['mailer' => ['record_subject' => true]]], 'traces.mailer.record_subject'];

        // Logs
        yield 'monolog_enabled' => ['monolog_enabled', ['monolog_enabled' => false], ['logs' => ['correlation' => ['enabled' => false]]], 'logs.correlation.enabled'];
        yield 'log_export_enabled' => ['log_export_enabled', ['log_export_enabled' => true], ['logs' => ['export' => ['enabled' => true]]], 'logs.export.enabled'];
        yield 'log_export_level' => ['log_export_level', ['log_export_level' => 'warning'], ['logs' => ['export' => ['level' => 'warning']]], 'logs.export.level'];
        yield 'log_export_capture_code_attributes' => ['log_export_capture_code_attributes', ['log_export_capture_code_attributes' => true], ['logs' => ['export' => ['capture_code_attributes' => true]]], 'logs.export.capture_code_attributes'];
        yield 'log_export_unprefixed_attributes' => ['log_export_unprefixed_attributes', ['log_export_unprefixed_attributes' => false], ['logs' => ['export' => ['unprefixed_attributes' => false]]], 'logs.export.unprefixed_attributes'];
    }
}
