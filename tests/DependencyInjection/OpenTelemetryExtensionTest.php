<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Traceway\OpenTelemetryBundle\DependencyInjection\OpenTelemetryExtension;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware as DoctrineTraceableMiddleware;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Monolog\TraceContextProcessor;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\TracingInterface;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;

final class OpenTelemetryExtensionTest extends TestCase
{
    public function testDefaultServicesRegistered(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->hasDefinition(Tracing::class));
        self::assertTrue($container->hasDefinition(OpenTelemetrySubscriber::class));
        self::assertTrue($container->hasDefinition(ConsoleSubscriber::class));
        self::assertTrue($container->hasDefinition(OpenTelemetryMiddleware::class));
        self::assertTrue($container->hasAlias(TracingInterface::class));
    }

    public function testHttpClientParametersSet(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->getParameter('open_telemetry.http_client_enabled'));
        self::assertSame('opentelemetry-symfony', $container->getParameter('open_telemetry.tracer_name'));
    }

    public function testHttpClientDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['http_client' => ['enabled' => false]]]);

        self::assertFalse($container->getParameter('open_telemetry.http_client_enabled'));
    }

    public function testTracerNameWiredToAllServices(): void
    {
        $container = $this->buildContainer([
            'traces' => ['tracer_name' => 'custom-tracer'],
        ]);

        $tracingDef = $container->getDefinition(Tracing::class);
        self::assertSame('custom-tracer', $tracingDef->getArgument('$tracerName'));

        $subscriberDef = $container->getDefinition(OpenTelemetrySubscriber::class);
        self::assertSame('custom-tracer', $subscriberDef->getArgument('$tracerName'));

        $consoleDef = $container->getDefinition(ConsoleSubscriber::class);
        self::assertSame('custom-tracer', $consoleDef->getArgument('$tracerName'));

        $middlewareDef = $container->getDefinition(OpenTelemetryMiddleware::class);
        self::assertSame('custom-tracer', $middlewareDef->getArgument('$tracerName'));
    }

    public function testSubscriberRemovedWhenTracesDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['enabled' => false]]);

        self::assertFalse($container->hasDefinition(OpenTelemetrySubscriber::class));
        self::assertTrue($container->hasDefinition(Tracing::class));
    }

    public function testConsoleSubscriberRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['console' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(ConsoleSubscriber::class));
        self::assertTrue($container->hasDefinition(OpenTelemetrySubscriber::class));
    }

    public function testConsoleSubscriberReceivesExcludedCommands(): void
    {
        $container = $this->buildContainer([
            'traces' => ['console' => ['excluded_commands' => ['cache:clear', 'assets:install']]],
        ]);

        $def = $container->getDefinition(ConsoleSubscriber::class);
        self::assertSame(['cache:clear', 'assets:install'], $def->getArgument('$excludedCommands'));
    }

    public function testMiddlewareRemovedWhenMessengerDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['messenger' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(OpenTelemetryMiddleware::class));
        self::assertTrue($container->hasDefinition(OpenTelemetrySubscriber::class));
    }

    public function testSubscriberReceivesConfig(): void
    {
        $container = $this->buildContainer([
            'traces' => [
                'excluded_paths' => ['/health'],
                'record_client_ip' => false,
                'error_status_threshold' => 400,
            ],
        ]);

        $def = $container->getDefinition(OpenTelemetrySubscriber::class);

        self::assertSame(['/health'], $def->getArgument('$excludedPaths'));
        self::assertFalse($def->getArgument('$recordClientIp'));
        self::assertSame(400, $def->getArgument('$errorStatusThreshold'));
    }

    public function testMiddlewareReceivesRootSpansConfig(): void
    {
        $container = $this->buildContainer(['traces' => ['messenger' => ['root_spans' => true]]]);

        $def = $container->getDefinition(OpenTelemetryMiddleware::class);
        self::assertTrue($def->getArgument('$rootSpans'));
    }

    public function testMiddlewareRootSpansDefaultFalse(): void
    {
        $container = $this->buildContainer([]);

        $def = $container->getDefinition(OpenTelemetryMiddleware::class);
        self::assertFalse($def->getArgument('$rootSpans'));
    }

    public function testPrependRegistersMessengerMiddleware(): void
    {
        $container = new ContainerBuilder();
        $extension = new OpenTelemetryExtension();
        $extension->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        self::assertNotEmpty($frameworkConfigs);

        $messengerConfig = $frameworkConfigs[0]['messenger'] ?? null;
        self::assertNotNull($messengerConfig);

        $middleware = $messengerConfig['buses']['messenger.bus.default']['middleware'] ?? [];
        self::assertContains(OpenTelemetryMiddleware::class, $middleware);
    }

    public function testPrependSkippedWhenMessengerDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('open_telemetry', ['traces' => ['messenger' => ['enabled' => false]]]);

        $extension = new OpenTelemetryExtension();
        $extension->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        self::assertEmpty($frameworkConfigs);
    }

    public function testDoctrineMiddlewareRegisteredWhenEnabled(): void
    {
        $container = $this->buildContainer(['traces' => ['doctrine' => ['enabled' => true]]]);

        self::assertTrue($container->hasDefinition(DoctrineTraceableMiddleware::class));

        $def = $container->getDefinition(DoctrineTraceableMiddleware::class);
        self::assertTrue($def->hasTag('doctrine.middleware'));
        self::assertTrue($def->getArgument('$recordStatements'));
    }

    public function testDoctrineMiddlewareNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['doctrine' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(DoctrineTraceableMiddleware::class));
    }

    public function testDoctrineRecordStatementsConfigured(): void
    {
        $container = $this->buildContainer([
            'traces' => ['doctrine' => ['enabled' => true, 'record_statements' => false]],
        ]);

        $def = $container->getDefinition(DoctrineTraceableMiddleware::class);
        self::assertFalse($def->getArgument('$recordStatements'));
    }

    public function testDoctrineTracerNameWired(): void
    {
        $container = $this->buildContainer([
            'traces' => ['tracer_name' => 'my-tracer', 'doctrine' => ['enabled' => true]],
        ]);

        $def = $container->getDefinition(DoctrineTraceableMiddleware::class);
        self::assertSame('my-tracer', $def->getArgument('$tracerName'));
    }

    public function testCacheEnabledParameterSetByDefault(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->getParameter('open_telemetry.cache_enabled'));
    }

    public function testCacheExcludedPoolsParameterSet(): void
    {
        $container = $this->buildContainer([
            'traces' => ['cache' => ['excluded_pools' => ['cache.system', 'cache.validator']]],
        ]);

        self::assertSame(
            ['cache.system', 'cache.validator'],
            $container->getParameter('open_telemetry.cache_excluded_pools'),
        );
    }

    public function testCacheExcludedPoolsDefaultEmpty(): void
    {
        $container = $this->buildContainer([]);

        self::assertSame([], $container->getParameter('open_telemetry.cache_excluded_pools'));
    }

    public function testCacheDisabledParameter(): void
    {
        $container = $this->buildContainer(['traces' => ['cache' => ['enabled' => false]]]);

        self::assertFalse($container->getParameter('open_telemetry.cache_enabled'));
    }

    public function testTwigExtensionRegisteredWhenEnabled(): void
    {
        $container = $this->buildContainer(['traces' => ['twig' => ['enabled' => true]]]);

        self::assertTrue($container->hasDefinition(OpenTelemetryTwigExtension::class));

        $def = $container->getDefinition(OpenTelemetryTwigExtension::class);
        self::assertTrue($def->hasTag('twig.extension'));
    }

    public function testTwigExtensionNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['twig' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(OpenTelemetryTwigExtension::class));
    }

    public function testTwigExtensionTracerNameWired(): void
    {
        $container = $this->buildContainer([
            'traces' => ['tracer_name' => 'my-tracer', 'twig' => ['enabled' => true]],
        ]);

        $def = $container->getDefinition(OpenTelemetryTwigExtension::class);
        self::assertSame('my-tracer', $def->getArgument('$tracerName'));
    }

    public function testTwigExtensionExcludedTemplatesWired(): void
    {
        $container = $this->buildContainer([
            'traces' => ['twig' => ['enabled' => true, 'excluded_templates' => ['@WebProfiler/', '@Debug/']]],
        ]);

        $def = $container->getDefinition(OpenTelemetryTwigExtension::class);
        self::assertSame(['@WebProfiler/', '@Debug/'], $def->getArgument('$excludedTemplates'));
    }

    public function testTwigExtensionExcludedTemplatesDefaultEmpty(): void
    {
        $container = $this->buildContainer(['traces' => ['twig' => ['enabled' => true]]]);

        $def = $container->getDefinition(OpenTelemetryTwigExtension::class);
        self::assertSame([], $def->getArgument('$excludedTemplates'));
    }

    public function testMonologProcessorRegisteredByDefault(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->hasDefinition(TraceContextProcessor::class));

        $def = $container->getDefinition(TraceContextProcessor::class);
        self::assertTrue($def->hasTag('monolog.processor'));
    }

    public function testMonologProcessorNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(['logs' => ['correlation' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(TraceContextProcessor::class));
    }

    public function testLogExportCompilesWithMonologBundleRegisteredFirst(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new \Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension());

        $extension = new OpenTelemetryExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('open_telemetry', ['logs' => ['export' => ['enabled' => true]]]);

        $extension->prepend($container);

        $monologConfigs = $container->getExtensionConfig('monolog');
        self::assertNotEmpty($monologConfigs, 'OTel prepend should inject monolog handler config');

        $handlerConfig = $monologConfigs[0]['handlers']['opentelemetry'] ?? null;
        self::assertNotNull($handlerConfig, 'opentelemetry handler should be prepended');
        self::assertSame('service', $handlerConfig['type']);
        self::assertSame(OtelLogHandler::class, $handlerConfig['id']);

        self::assertTrue(
            $container->hasDefinition(OtelLogHandler::class),
            'OtelLogHandler service must be registered in prepend() so it exists before MonologBundle compiles',
        );
        self::assertTrue($container->hasDefinition(OtelLoggerFlushSubscriber::class));
    }

    public function testLogExportEnabledThrowsWhenMonologBundleMissing(): void
    {
        $container = new ContainerBuilder();
        $extension = new OpenTelemetryExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('open_telemetry', ['logs' => ['export' => ['enabled' => true]]]);

        self::expectException(\LogicException::class);
        self::expectExceptionMessage('symfony/monolog-bundle');

        $extension->prepend($container);
    }

    public function testSdkParameterNotAddedWhenNotEnabled(): void
    {
        $container = $this->buildContainer([]);

        self::assertFalse($container->hasParameter('open_telemetry.sdk.config'));
    }

    public function testSdkParameterDefaultValuesAddedWhenEnabled(): void
    {
        $container = $this->buildContainer(['sdk' => ['enabled' => true]]);

        self::assertTrue($container->hasParameter('open_telemetry.sdk.config'));

        $config = $container->getParameter('open_telemetry.sdk.config');
        self::assertIsArray($config);
        self::assertIsArray($config['resource_attributes']);
        self::assertIsArray($config['exporter_otlp_headers']);
        self::assertFalse($config['use_putenv']);
        self::assertFalse($config['autoload_enabled']);
    }

    public function testSdkParameterAreAddedIfConfiguredAndAutomaticallyEnabled(): void
    {
        $container = $this->buildContainer(['sdk' => ['autoload_enabled' => true]]);

        self::assertTrue($container->hasParameter('open_telemetry.sdk.config'));

        $config = $container->getParameter('open_telemetry.sdk.config');
        self::assertIsArray($config);
        self::assertIsArray($config['resource_attributes']);
        self::assertIsArray($config['exporter_otlp_headers']);
        self::assertFalse($config['use_putenv']);
        self::assertTrue($config['autoload_enabled']);
    }

    public function testSdkParameterNotAddedIfExplicitlyDisabledWithOtherConfigurationValues(): void
    {
        $container = $this->buildContainer(['sdk' => ['enabled' => false, 'autoload_enabled' => true]]);

        self::assertFalse($container->hasParameter('open_telemetry.sdk.config'));
    }

    public function testSdkParameterConfigIsSetAsParameter(): void
    {
        $expected = [
            'enabled' => true,
            'autoload_enabled' => true,
            'use_putenv' => true,
            'resource_attributes' => ['service.version' => '1.0', 'deployment.environment' => 'dev'],
            'exporter_otlp_headers' => ['other-config-value' => 'abc', 'Authorization' => 'api-key'],
        ];

        $container = $this->buildContainer(['sdk' => $expected]);

        self::assertTrue($container->hasParameter('open_telemetry.sdk.config'));

        self::assertSame($expected, $container->getParameter('open_telemetry.sdk.config'));
    }

    private function buildContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new OpenTelemetryExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
