<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Traceway\OpenTelemetryBundle\DependencyInjection\OpenTelemetryExtension;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware as DoctrineTraceableMiddleware;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetryMetricsSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelMetricsFlushSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\SchedulerSubscriber;
use Traceway\OpenTelemetryBundle\Mailer\MeteredTransports;
use Traceway\OpenTelemetryBundle\Mailer\TraceableMailer;
use Traceway\OpenTelemetryBundle\Mailer\TraceableTransports;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Metrics\MetricFlusher;
use Traceway\OpenTelemetryBundle\Metrics\MetricFlusherInterface;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Monolog\TraceContextProcessor;
use Traceway\OpenTelemetryBundle\Tests\Fixtures\ApplicationDoctorCheck;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\TracingInterface;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;

final class OpenTelemetryExtensionTest extends TestCase
{
    public function testDefaultServicesRegistered(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->has(Tracing::class));
        self::assertTrue($container->has(OpenTelemetrySubscriber::class));
        self::assertTrue($container->has(ConsoleSubscriber::class));
        self::assertTrue($container->has(OpenTelemetryMiddleware::class));
        self::assertTrue($container->hasAlias(TracingInterface::class));
    }

    public function testMetricFlushIsRegisteredWithMetrics(): void
    {
        $container = $this->buildContainer([
            'metrics' => ['enabled' => true],
        ]);

        self::assertTrue($container->has(MetricFlusher::class));
        self::assertTrue($container->hasAlias(MetricFlusherInterface::class));
        self::assertTrue($container->has(OtelMetricsFlushSubscriber::class));
    }

    public function testUnsetIntervalDefersToTheSdkCadence(): void
    {
        // The bundle does not invent a number: null lets MetricFlusher read
        // OTEL_METRIC_EXPORT_INTERVAL, or the SDK's default for it.
        $container = $this->buildContainer([
            'metrics' => ['enabled' => true],
        ]);

        self::assertNull($container->findDefinition(MetricFlusher::class)->getArgument('$intervalSeconds'));
    }

    public function testMetricFlushIntervalIsConfigurable(): void
    {
        $container = $this->buildContainer([
            'metrics' => ['enabled' => true, 'flush' => ['interval' => 15.0]],
        ]);

        self::assertSame(15.0, $container->findDefinition(MetricFlusher::class)->getArgument('$intervalSeconds'));
    }

    public function testMetricFlushCanBeDisabledOnItsOwn(): void
    {
        $container = $this->buildContainer([
            'metrics' => ['enabled' => true, 'flush' => ['enabled' => false]],
        ]);

        self::assertFalse($container->has(MetricFlusher::class));
        self::assertFalse($container->hasAlias(MetricFlusherInterface::class));
        self::assertFalse($container->has(OtelMetricsFlushSubscriber::class));
    }

    public function testMetricFlushIsRemovedWhenMetricsAreOff(): void
    {
        // Nothing records, so there is nothing to export.
        $container = $this->buildContainer([]);

        self::assertFalse($container->has(MetricFlusher::class));
        self::assertFalse($container->has(OtelMetricsFlushSubscriber::class));
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

        $tracingDef = $container->findDefinition(Tracing::class);
        self::assertSame('custom-tracer', $tracingDef->getArgument('$tracerName'));

        $subscriberDef = $container->findDefinition(OpenTelemetrySubscriber::class);
        self::assertSame('custom-tracer', $subscriberDef->getArgument('$tracerName'));

        $consoleDef = $container->findDefinition(ConsoleSubscriber::class);
        self::assertSame('custom-tracer', $consoleDef->getArgument('$tracerName'));

        $middlewareDef = $container->findDefinition(OpenTelemetryMiddleware::class);
        self::assertSame('custom-tracer', $middlewareDef->getArgument('$tracerName'));
    }

    public function testSubscriberRemovedWhenTracesDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['enabled' => false]]);

        self::assertFalse($container->has(OpenTelemetrySubscriber::class));
        self::assertTrue($container->has(Tracing::class));
    }

    public function testTraceSubsystemsDisabledWhenTracesDisabled(): void
    {
        $container = $this->buildContainer([
            'traces' => [
                'enabled' => false,
                'http_client' => ['enabled' => true],
                'console' => ['enabled' => true],
                'messenger' => ['enabled' => true],
                'doctrine' => ['enabled' => true],
                'cache' => ['enabled' => true],
                'twig' => ['enabled' => true],
                'scheduler' => ['enabled' => true],
                'mailer' => ['enabled' => true],
            ],
        ]);

        self::assertFalse($container->getParameter('open_telemetry.http_client_enabled'));
        self::assertFalse($container->getParameter('open_telemetry.traces.messenger.enabled'));
        self::assertFalse($container->getParameter('open_telemetry.cache_enabled'));

        self::assertFalse($container->has(OpenTelemetrySubscriber::class));
        self::assertFalse($container->has(ConsoleSubscriber::class));
        self::assertFalse($container->has(OpenTelemetryMiddleware::class));
        self::assertFalse($container->has(DoctrineTraceableMiddleware::class));
        self::assertFalse($container->has(OpenTelemetryTwigExtension::class));
        self::assertFalse($container->has(SchedulerSubscriber::class));
        self::assertFalse($container->has(TraceableMailer::class));
        self::assertFalse($container->has(TraceableTransports::class));
    }

    public function testConsoleSubscriberRemovedWhenDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['console' => ['enabled' => false]]]);

        self::assertFalse($container->has(ConsoleSubscriber::class));
        self::assertTrue($container->has(OpenTelemetrySubscriber::class));
    }

    public function testConsoleSubscriberReceivesExcludedCommands(): void
    {
        $container = $this->buildContainer([
            'traces' => ['console' => ['excluded_commands' => ['cache:clear', 'assets:install']]],
        ]);

        $def = $container->findDefinition(ConsoleSubscriber::class);
        self::assertSame(
            ['cache:clear', 'assets:install', 'messenger:consume', 'messenger:consume-messages'],
            $def->getArgument('$excludedCommands'),
            'user list extends the built-in long-running defaults instead of replacing them',
        );
    }

    public function testExcludedCommandsAreNotDuplicatedWhenUserRepeatsDefaults(): void
    {
        $container = $this->buildContainer([
            'traces' => ['console' => ['excluded_commands' => ['messenger:consume', 'cache:clear']]],
        ]);

        $def = $container->findDefinition(ConsoleSubscriber::class);
        self::assertSame(
            ['messenger:consume', 'cache:clear', 'messenger:consume-messages'],
            $def->getArgument('$excludedCommands'),
        );
    }

    public function testTraceLongRunningCommandsDropsTheBuiltInExclusions(): void
    {
        $container = $this->buildContainer([
            'traces' => ['console' => [
                'excluded_commands' => ['cache:clear'],
                'trace_long_running_commands' => true,
            ]],
        ]);

        $def = $container->findDefinition(ConsoleSubscriber::class);
        self::assertSame(['cache:clear'], $def->getArgument('$excludedCommands'));
    }

    public function testMiddlewareRemovedWhenMessengerDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['messenger' => ['enabled' => false]]]);

        self::assertFalse($container->has(OpenTelemetryMiddleware::class));
        self::assertTrue($container->has(OpenTelemetrySubscriber::class));
    }

    public function testSubscriberReceivesConfig(): void
    {
        $container = $this->buildContainer([
            'traces' => [
                'excluded_paths' => ['/health'],
                'record_client_ip' => false,
                'error_status_threshold' => 503,
            ],
        ]);

        $def = $container->findDefinition(OpenTelemetrySubscriber::class);

        self::assertSame(['/health'], $def->getArgument('$excludedPaths'));
        self::assertFalse($def->getArgument('$recordClientIp'));
        self::assertSame(503, $def->getArgument('$errorStatusThreshold'));
    }

    public function testMiddlewareReceivesRootSpansConfig(): void
    {
        $container = $this->buildContainer(['traces' => ['messenger' => ['root_spans' => true]]]);

        $def = $container->findDefinition(OpenTelemetryMiddleware::class);
        self::assertTrue($def->getArgument('$rootSpans'));
    }

    public function testMiddlewareRootSpansDefaultFalse(): void
    {
        $container = $this->buildContainer([]);

        $def = $container->findDefinition(OpenTelemetryMiddleware::class);
        self::assertFalse($def->getArgument('$rootSpans'));
    }

    public function testPrependDoesNotInjectMessengerMiddlewareConfig(): void
    {
        $container = new ContainerBuilder();
        $extension = new OpenTelemetryExtension();
        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('framework'));
    }

    public function testPrependRejectsEnvPlaceholdersWithClearError(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('open_telemetry', [
            'traces' => ['enabled' => '%env(bool:OTEL_ENABLED)%'],
        ]);

        $extension = new OpenTelemetryExtension();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/placeholders are not supported/');
        $extension->prepend($container);
    }

    public function testDoctrineMiddlewareRegisteredWhenEnabled(): void
    {
        $container = $this->buildContainer(['traces' => ['doctrine' => ['enabled' => true]]]);

        self::assertTrue($container->has(DoctrineTraceableMiddleware::class));

        $def = $container->findDefinition(DoctrineTraceableMiddleware::class);
        self::assertTrue($def->hasTag('doctrine.middleware'));
        self::assertFalse($def->getArgument('$recordStatements'));
        self::assertTrue($def->getArgument('$onlyWithParent'));
        self::assertSame(0, $def->getArgument('$maxSpansPerTrace'));
    }

    public function testDoctrineSpanCapConfigured(): void
    {
        $container = $this->buildContainer([
            'traces' => ['doctrine' => ['enabled' => true, 'max_spans_per_trace' => 250]],
        ]);

        $def = $container->findDefinition(DoctrineTraceableMiddleware::class);
        self::assertSame(250, $def->getArgument('$maxSpansPerTrace'));
    }

    public function testPsr18AndGuzzleParametersFollowHttpClientConfig(): void
    {
        $container = $this->buildContainer([]);
        self::assertTrue($container->getParameter('open_telemetry.http_client.psr18_enabled'));
        self::assertTrue($container->getParameter('open_telemetry.http_client.guzzle_enabled'));

        $container = $this->buildContainer(['traces' => ['http_client' => ['psr18' => false, 'guzzle' => false]]]);
        self::assertFalse($container->getParameter('open_telemetry.http_client.psr18_enabled'));
        self::assertFalse($container->getParameter('open_telemetry.http_client.guzzle_enabled'));

        $container = $this->buildContainer(['traces' => ['http_client' => ['enabled' => false]]]);
        self::assertFalse($container->getParameter('open_telemetry.http_client.psr18_enabled'));
        self::assertFalse($container->getParameter('open_telemetry.http_client.guzzle_enabled'));
    }

    public function testMailerMetricsDecorateTheTransportsInsideTheTracingDecorator(): void
    {
        $container = $this->buildContainer(['metrics' => ['enabled' => true, 'meter_name' => 'meter', 'mailer' => ['enabled' => true]]]);

        self::assertTrue($container->has(MeteredTransports::class));
        $definition = $container->findDefinition(MeteredTransports::class);
        self::assertSame(['mailer.transports', null, 8, ContainerInterface::IGNORE_ON_INVALID_REFERENCE], $definition->getDecoratedService());
        self::assertSame('meter', $definition->getArgument('$meterName'));
        self::assertTrue($definition->hasTag('kernel.reset'));

        $off = $this->buildContainer(['metrics' => ['enabled' => true, 'mailer' => ['enabled' => false]]]);
        self::assertFalse($off->has(MeteredTransports::class));
    }

    public function testApplicationChecksAreTaggedByAutoconfiguration(): void
    {
        $container = $this->buildContainer([]);
        $container->setDefinition('app.check', (new Definition(ApplicationDoctorCheck::class))->setAutoconfigured(true)->setPublic(true));
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();

        self::assertTrue($container->getDefinition('app.check')->hasTag('traceway.doctor.check'), 'docs/doctor.md promises autoconfigure tags custom checks');
    }

    public function testEveryBundleServiceUsesTheBundleAliasAndExplicitWiring(): void
    {
        $container = $this->buildContainer([
            'metrics' => ['enabled' => true, 'messenger' => ['enabled' => true], 'doctrine' => ['enabled' => true], 'http_server' => ['enabled' => true], 'http_client' => ['enabled' => true], 'mailer' => ['enabled' => true]],
            'logs' => ['correlation' => ['enabled' => true]],
        ]);

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with((string) $definition->getClass(), 'Traceway\\OpenTelemetryBundle\\')) {
                continue;
            }
            self::assertStringStartsWith('open_telemetry.', $id, "service {$id} must use the bundle alias");
            self::assertFalse($definition->isAutowired(), "{$id} must not be autowired");
            self::assertFalse($definition->isAutoconfigured(), "{$id} must not be autoconfigured");
            self::assertTrue($container->has((string) $definition->getClass()) || null !== $definition->getDecoratedService(), "the class-name id of {$id} must still resolve");
        }
    }

    public function testHttpClientExcludedServicesWired(): void
    {
        $container = $this->buildContainer(['traces' => ['http_client' => ['excluded_services' => ['app.legacy', 'app.legacy', 'app.sdk']]]]);

        self::assertSame(['app.legacy', 'app.sdk'], $container->getParameter('open_telemetry.http_client.excluded_services'));
    }

    public function testMessengerExcludedMessagesWired(): void
    {
        $container = $this->buildContainer([
            'traces' => ['messenger' => ['excluded_messages' => ['App\\Message\\MlFlats', 'App\\Message\\MlFlats', 'App\\Message\\Noise']]],
        ]);

        $def = $container->findDefinition(OpenTelemetryMiddleware::class);
        self::assertSame(['App\\Message\\MlFlats', 'App\\Message\\Noise'], $def->getArgument('$excludedMessages'));
    }

    public function testDoctrineMiddlewareNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['doctrine' => ['enabled' => false]]]);

        self::assertFalse($container->has(DoctrineTraceableMiddleware::class));
    }

    public function testDoctrineRecordStatementsConfigured(): void
    {
        $container = $this->buildContainer([
            'traces' => ['doctrine' => ['enabled' => true, 'record_statements' => true]],
        ]);

        $def = $container->findDefinition(DoctrineTraceableMiddleware::class);
        self::assertTrue($def->getArgument('$recordStatements'));
    }

    public function testDoctrineOnlyWithParentCanBeDisabled(): void
    {
        $container = $this->buildContainer([
            'traces' => ['doctrine' => ['enabled' => true, 'only_with_parent' => false]],
        ]);

        $def = $container->findDefinition(DoctrineTraceableMiddleware::class);
        self::assertFalse($def->getArgument('$onlyWithParent'));
    }

    public function testDoctrineTracerNameWired(): void
    {
        $container = $this->buildContainer([
            'traces' => ['tracer_name' => 'my-tracer', 'doctrine' => ['enabled' => true]],
        ]);

        $def = $container->findDefinition(DoctrineTraceableMiddleware::class);
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

        self::assertTrue($container->has(OpenTelemetryTwigExtension::class));

        $def = $container->findDefinition(OpenTelemetryTwigExtension::class);
        self::assertTrue($def->hasTag('twig.extension'));
    }

    public function testTwigExtensionNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(['traces' => ['twig' => ['enabled' => false]]]);

        self::assertFalse($container->has(OpenTelemetryTwigExtension::class));
    }

    public function testTwigExtensionTracerNameWired(): void
    {
        $container = $this->buildContainer([
            'traces' => ['tracer_name' => 'my-tracer', 'twig' => ['enabled' => true]],
        ]);

        $def = $container->findDefinition(OpenTelemetryTwigExtension::class);
        self::assertSame('my-tracer', $def->getArgument('$tracerName'));
    }

    public function testTwigExtensionExcludedTemplatesWired(): void
    {
        $container = $this->buildContainer([
            'traces' => ['twig' => ['enabled' => true, 'excluded_templates' => ['@WebProfiler/', '@Debug/']]],
        ]);

        $def = $container->findDefinition(OpenTelemetryTwigExtension::class);
        self::assertSame(['@WebProfiler/', '@Debug/'], $def->getArgument('$excludedTemplates'));
    }

    public function testTwigExtensionExcludedTemplatesDefaultEmpty(): void
    {
        $container = $this->buildContainer(['traces' => ['twig' => ['enabled' => true]]]);

        $def = $container->findDefinition(OpenTelemetryTwigExtension::class);
        self::assertSame([], $def->getArgument('$excludedTemplates'));
    }

    public function testMonologProcessorRegisteredByDefault(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->has(TraceContextProcessor::class));

        $def = $container->findDefinition(TraceContextProcessor::class);
        self::assertTrue($def->hasTag('monolog.processor'));
    }

    public function testMonologProcessorNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(['logs' => ['correlation' => ['enabled' => false]]]);

        self::assertFalse($container->has(TraceContextProcessor::class));
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
        self::assertSame('open_telemetry.logs.handler', $handlerConfig['id']);

        self::assertTrue(
            $container->has(OtelLogHandler::class),
            'OtelLogHandler service must be registered in prepend() so it exists before MonologBundle compiles',
        );
        self::assertTrue($container->has(OtelLoggerFlushSubscriber::class));
    }

    public function testLogExportExcludedHttpCodesWiredToHandler(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new \Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension());

        $extension = new OpenTelemetryExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('open_telemetry', [
            'logs' => ['export' => ['enabled' => true, 'excluded_http_codes' => [404, 405]]],
        ]);

        $extension->prepend($container);

        $handlerDef = $container->findDefinition(OtelLogHandler::class);
        self::assertSame([404, 405], $handlerDef->getArgument('$excludedHttpCodes'));
    }

    public function testLogExportExcludedChannelsWiredToHandler(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new \Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension());

        $extension = new OpenTelemetryExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('open_telemetry', [
            'logs' => ['export' => ['enabled' => true, 'excluded_channels' => ['deprecation', 'php']]],
        ]);

        $extension->prepend($container);

        $handlerDef = $container->findDefinition(OtelLogHandler::class);
        self::assertSame(['deprecation', 'php'], $handlerDef->getArgument('$excludedChannels'));
    }

    public function testMetricsExclusionsAreIndependentOfTraceExclusions(): void
    {
        $container = $this->buildContainer([
            'traces' => ['excluded_paths' => ['/health'], 'http_client' => ['excluded_hosts' => ['collector.internal']]],
            'metrics' => ['enabled' => true, 'http_server' => ['enabled' => true], 'http_client' => ['enabled' => true]],
        ]);

        $def = $container->findDefinition(OpenTelemetryMetricsSubscriber::class);
        self::assertSame([], $def->getArgument('$excludedPaths'), '/health stays measured unless metrics exclude it too');
        self::assertSame([], $container->getParameter('open_telemetry.http_client_metrics_excluded_hosts'));
    }

    public function testMetricsExcludedPathsWiredWhenSetExplicitly(): void
    {
        $container = $this->buildContainer([
            'traces' => ['excluded_paths' => ['/health']],
            'metrics' => ['enabled' => true, 'http_server' => ['enabled' => true, 'excluded_paths' => ['/metrics']]],
        ]);

        $def = $container->findDefinition(OpenTelemetryMetricsSubscriber::class);
        self::assertSame(['/metrics'], $def->getArgument('$excludedPaths'));
    }

    public function testRecordExceptionMinStatusWiredToSubscriber(): void
    {
        $container = $this->buildContainer(['traces' => ['record_exception_min_status' => 500]]);

        $def = $container->findDefinition(OpenTelemetrySubscriber::class);
        self::assertSame(500, $def->getArgument('$recordExceptionMinStatus'));
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
