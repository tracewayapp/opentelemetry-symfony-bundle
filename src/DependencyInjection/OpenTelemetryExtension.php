<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection;

use Doctrine\DBAL\Driver\Middleware as DoctrineMiddleware;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredMiddleware as DoctrineMeteredMiddleware;
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
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistry;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistryInterface;
use Traceway\OpenTelemetryBundle\Metrics\MetricFlusher;
use Traceway\OpenTelemetryBundle\Metrics\MetricFlusherInterface;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Monolog\TraceContextProcessor;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;
use Traceway\OpenTelemetryBundle\XRay\XRayBootstrapper;

final class OpenTelemetryExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());

        try {
            /** @var array{logs: array{export: array{enabled: bool, level: string, capture_code_attributes: bool, unprefixed_attributes: bool, excluded_http_codes: list<int>, excluded_channels: list<string>}}} $config */
            $config = $this->processConfiguration(new Configuration(), $configs);
        } catch (\Symfony\Component\Config\Definition\Exception\InvalidTypeException $e) {
            if ($this->containsEnvPlaceholder($configs)) {
                throw new \LogicException('The "open_telemetry" configuration is consumed at compile time to wire services, so "%env()%" placeholders are not supported for its options. Use plain values (per-environment config files) instead.', 0, $e);
            }

            throw $e;
        }

        if ($config['logs']['export']['enabled']) {
            if (!$container->hasExtension('monolog')) {
                throw new \LogicException('The "open_telemetry.logs.export.enabled" option requires symfony/monolog-bundle to be installed and enabled. Run "composer require symfony/monolog-bundle" or set "logs.export.enabled: false".');
            }

            $container->prependExtensionConfig('monolog', [
                'handlers' => [
                    'opentelemetry' => [
                        'type' => 'service',
                        'id' => 'open_telemetry.logs.handler',
                    ],
                ],
            ]);

            $handlerDef = new Definition(OtelLogHandler::class);
            $handlerDef->setArgument('$level', $config['logs']['export']['level']);
            $handlerDef->setArgument('$captureCodeAttributes', $config['logs']['export']['capture_code_attributes']);
            $handlerDef->setArgument('$unprefixedAttributes', $config['logs']['export']['unprefixed_attributes']);
            $handlerDef->setArgument('$excludedHttpCodes', $config['logs']['export']['excluded_http_codes']);
            $handlerDef->setArgument('$excludedChannels', $config['logs']['export']['excluded_channels']);
            $handlerDef->addTag('kernel.reset', ['method' => 'reset']);
            self::register($container, 'open_telemetry.logs.handler', $handlerDef);

            $flushDef = new Definition(OtelLoggerFlushSubscriber::class);
            $flushDef->addTag('kernel.event_subscriber');
            self::register($container, 'open_telemetry.logs.flush_subscriber', $flushDef);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{traces: array{enabled: bool, propagator: string, id_generator: string, tracer_name: string, excluded_paths: list<string>, record_client_ip: bool, error_status_threshold: int, record_exception_min_status: int, console: array{enabled: bool, excluded_commands: list<string>, trace_long_running_commands: bool}, http_client: array{enabled: bool, excluded_hosts: list<string>, excluded_services: list<string>, psr18: bool, guzzle: bool}, messenger: array{enabled: bool, root_spans: bool, excluded_messages: list<string>}, doctrine: array{enabled: bool, record_statements: bool, only_with_parent: bool, max_spans_per_trace: int}, cache: array{enabled: bool, excluded_pools: list<string>}, twig: array{enabled: bool, excluded_templates: list<string>}, scheduler: array{enabled: bool}, mailer: array{enabled: bool, record_subject: bool}}, metrics: array{enabled: bool, meter_name: string, temporality: ?string, flush: array{enabled: bool, interval: int|float|null}, messenger: array{enabled: bool, excluded_queues: list<string>}, doctrine: array{enabled: bool}, http_server: array{enabled: bool, excluded_paths: list<string>}, http_client: array{enabled: bool, excluded_hosts: list<string>}, mailer: array{enabled: bool}}, logs: array{correlation: array{enabled: bool}, export: array{enabled: bool, level: string, capture_code_attributes: bool, unprefixed_attributes: bool, excluded_http_codes: list<int>, excluded_channels: list<string>}}, sdk: array{enabled: bool, autoload_enabled: bool, use_putenv: bool, resource_attributes: array<string, string>, exporter_otlp_headers: array<string, string>}} $config */
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        // Applications' own checks get the tag the doctor collects, as docs/doctor.md promises.
        $container->registerForAutoconfiguration(CheckInterface::class)->addTag('traceway.doctor.check');

        $sdk = $config['sdk'];

        $traces = $config['traces'];
        $tracingEnabled = $traces['enabled'];
        $tracerName = $traces['tracer_name'];

        $container->getDefinition('open_telemetry.tracing')
            ->setArgument('$tracerName', $tracerName);

        $httpClientEnabled = $tracingEnabled && $traces['http_client']['enabled'] && $this->isHttpClientAvailable();
        $container->setParameter('open_telemetry.http_client.psr18_enabled', $tracingEnabled && $traces['http_client']['enabled'] && $traces['http_client']['psr18'] && interface_exists(\Psr\Http\Client\ClientInterface::class));
        $container->setParameter('open_telemetry.http_client.guzzle_enabled', $tracingEnabled && $traces['http_client']['enabled'] && $traces['http_client']['guzzle'] && class_exists(\GuzzleHttp\Client::class));
        $messengerTracingEnabled = $tracingEnabled && $traces['messenger']['enabled'];
        $container->setParameter('open_telemetry.http_client_enabled', $httpClientEnabled);
        $container->setParameter('open_telemetry.tracer_name', $tracerName);

        $container->setParameter('open_telemetry.traces.enabled', $tracingEnabled);
        $container->setParameter('open_telemetry.traces.propagator', $traces['propagator']);
        $container->setParameter('open_telemetry.traces.id_generator', $traces['id_generator']);
        $container->setParameter('open_telemetry.traces.messenger.enabled', $messengerTracingEnabled);
        $container->setParameter('open_telemetry.metrics.enabled', $config['metrics']['enabled']);
        $container->setParameter('open_telemetry.metrics.temporality', $config['metrics']['temporality']);
        $container->setParameter('open_telemetry.logs.export.enabled', $config['logs']['export']['enabled']);

        /** @var string[] $httpExcludedHosts */
        $httpExcludedHosts = $traces['http_client']['excluded_hosts'];
        $container->setParameter('open_telemetry.http_client_excluded_hosts', $httpExcludedHosts);
        $container->setParameter('open_telemetry.http_client.excluded_services', array_values(array_unique($traces['http_client']['excluded_services'])));

        if ($sdk['enabled']) {
            $container->setParameter('open_telemetry.sdk.config', $sdk);
        }

        if ($tracingEnabled) {
            $container->getDefinition('open_telemetry.http_server.subscriber')
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedPaths', $traces['excluded_paths'])
                ->setArgument('$recordClientIp', $traces['record_client_ip'])
                ->setArgument('$errorStatusThreshold', $traces['error_status_threshold'])
                ->setArgument('$recordExceptionMinStatus', $traces['record_exception_min_status']);
        } else {
            self::remove($container, 'open_telemetry.http_server.subscriber', OpenTelemetrySubscriber::class);
        }

        if ($tracingEnabled && $traces['console']['enabled']) {
            $container->getDefinition('open_telemetry.console.subscriber')
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedCommands', $this->resolveExcludedCommands($traces['console']));
        } else {
            self::remove($container, 'open_telemetry.console.subscriber', ConsoleSubscriber::class);
        }

        $schedulerEnabled = $tracingEnabled && $traces['scheduler']['enabled'] && $this->isSchedulerAvailable();

        if ($messengerTracingEnabled && $this->isMessengerAvailable()) {
            $container->getDefinition('open_telemetry.messenger.middleware')
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$rootSpans', $traces['messenger']['root_spans'])
                ->setArgument('$excludeScheduledMessages', $schedulerEnabled)
                ->setArgument('$excludedMessages', array_values(array_unique($traces['messenger']['excluded_messages'])));
        } else {
            self::remove($container, 'open_telemetry.messenger.middleware', OpenTelemetryMiddleware::class);
        }

        if ($schedulerEnabled) {
            $schedulerDef = new Definition(SchedulerSubscriber::class);
            $schedulerDef->setArgument('$tracerName', $tracerName);
            $schedulerDef->addTag('kernel.event_subscriber');
            $schedulerDef->addTag('kernel.reset', ['method' => 'reset']);
            self::register($container, 'open_telemetry.scheduler.subscriber', $schedulerDef);
        }

        $container->setParameter('open_telemetry.traces.doctrine.enabled', $tracingEnabled && $traces['doctrine']['enabled'] && $this->isDoctrineAvailable());

        if ($tracingEnabled && $traces['doctrine']['enabled'] && $this->isDoctrineAvailable()) {
            $definition = new Definition(DoctrineTraceableMiddleware::class);
            $definition->setArgument('$tracerName', $tracerName);
            $definition->setArgument('$recordStatements', $traces['doctrine']['record_statements']);
            $definition->setArgument('$onlyWithParent', $traces['doctrine']['only_with_parent']);
            $definition->setArgument('$maxSpansPerTrace', $traces['doctrine']['max_spans_per_trace']);
            $definition->addTag('doctrine.middleware');
            self::register($container, 'open_telemetry.doctrine.tracing_middleware', $definition);
        }

        $cacheEnabled = $tracingEnabled && $traces['cache']['enabled'] && $this->isCacheAvailable();
        $container->setParameter('open_telemetry.cache_enabled', $cacheEnabled);
        /** @var string[] $cacheExcludedPools */
        $cacheExcludedPools = $traces['cache']['excluded_pools'];
        $container->setParameter('open_telemetry.cache_excluded_pools', $cacheExcludedPools);

        if ($tracingEnabled && $traces['twig']['enabled'] && $this->isTwigAvailable()) {
            /** @var string[] $twigExcluded */
            $twigExcluded = $traces['twig']['excluded_templates'];
            $twigExtDef = new Definition(OpenTelemetryTwigExtension::class);
            $twigExtDef->setArgument('$tracerName', $tracerName);
            $twigExtDef->setArgument('$excludedTemplates', $twigExcluded);
            $twigExtDef->addTag('twig.extension');
            $twigExtDef->addTag('kernel.reset', ['method' => 'reset']);
            self::register($container, 'open_telemetry.twig.extension', $twigExtDef);
        }

        if ($tracingEnabled && $traces['mailer']['enabled'] && $this->isMailerAvailable()) {
            $mailerDef = new Definition(TraceableMailer::class);
            $mailerDef->setDecoratedService('mailer.mailer', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            $mailerDef->setArgument('$decorated', new Reference('.inner'));
            $mailerDef->setArgument('$tracerName', $tracerName);
            $mailerDef->setArgument('$recordSubject', $traces['mailer']['record_subject']);
            $mailerDef->addTag('kernel.reset', ['method' => 'reset']);
            self::register($container, 'open_telemetry.mailer.traceable_mailer', $mailerDef);

            $transportsDef = new Definition(TraceableTransports::class);
            $transportsDef->setDecoratedService('mailer.transports', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            $transportsDef->setArgument('$decorated', new Reference('.inner'));
            $transportsDef->setArgument('$tracerName', $tracerName);
            $transportsDef->addTag('kernel.reset', ['method' => 'reset']);
            self::register($container, 'open_telemetry.mailer.traceable_transports', $transportsDef);
        }

        $container->setParameter('open_telemetry.logs.correlation.enabled', $config['logs']['correlation']['enabled'] && $this->isMonologAvailable());

        if ($config['logs']['correlation']['enabled'] && $this->isMonologAvailable()) {
            $monologDef = new Definition(TraceContextProcessor::class);
            $monologDef->addTag('monolog.processor');
            self::register($container, 'open_telemetry.logs.trace_context_processor', $monologDef);
        }

        $propagator = $config['traces']['propagator'];
        $idGenerator = $config['traces']['id_generator'];

        if ('w3c' !== $propagator || 'default' !== $idGenerator) {
            if (!$this->isXRayAvailable()) {
                throw new \LogicException('X-Ray support requires "open-telemetry/contrib-aws". Run: composer require open-telemetry/contrib-aws');
            }
            $xrayDef = new Definition(XRayBootstrapper::class);
            $xrayDef->setArgument('$propagator', $propagator);
            $xrayDef->setArgument('$idGenerator', $idGenerator);
            $xrayDef->addTag('kernel.event_subscriber');
            self::register($container, 'open_telemetry.xray.bootstrapper', $xrayDef);
        }

        $metrics = $config['metrics'];
        $meterName = $metrics['meter_name'];

        if ($metrics['enabled']) {
            $container->getDefinition('open_telemetry.metrics.registry')
                ->setArgument('$meterName', $meterName);
        } else {
            self::remove($container, 'open_telemetry.metrics.registry', MeterRegistry::class, MeterRegistryInterface::class);
        }

        if ($metrics['enabled'] && $metrics['flush']['enabled']) {
            $container->getDefinition('open_telemetry.metrics.flusher')
                ->setArgument('$intervalSeconds', $metrics['flush']['interval']);
        } else {
            self::remove($container, 'open_telemetry.metrics.flusher', MetricFlusher::class, MetricFlusherInterface::class);
            self::remove($container, 'open_telemetry.metrics.flush_subscriber', OtelMetricsFlushSubscriber::class);
        }

        if ($metrics['enabled'] && $metrics['messenger']['enabled'] && $this->isMessengerAvailable()) {
            $container->getDefinition('open_telemetry.messenger.metrics_middleware')
                ->setArgument('$meterName', $meterName)
                ->setArgument('$excludedQueues', $metrics['messenger']['excluded_queues']);
        } else {
            self::remove($container, 'open_telemetry.messenger.metrics_middleware', OpenTelemetryMetricsMiddleware::class);
        }

        if ($metrics['enabled'] && $metrics['doctrine']['enabled'] && $this->isDoctrineAvailable()) {
            $definition = new Definition(DoctrineMeteredMiddleware::class);
            $definition->setArgument('$meterName', $meterName);
            $definition->addTag('doctrine.middleware');
            self::register($container, 'open_telemetry.doctrine.metrics_middleware', $definition);
        }

        if ($metrics['enabled'] && $metrics['http_server']['enabled']) {
            $container->getDefinition('open_telemetry.http_server.metrics_subscriber')
                ->setArgument('$meterName', $meterName)
                ->setArgument('$excludedPaths', $metrics['http_server']['excluded_paths'])
                ->setArgument('$errorStatusThreshold', $traces['error_status_threshold']);
        } else {
            self::remove($container, 'open_telemetry.http_server.metrics_subscriber', OpenTelemetryMetricsSubscriber::class);
        }

        $httpClientMetricsEnabled = $metrics['enabled'] && $metrics['http_client']['enabled'] && $this->isHttpClientAvailable();
        $container->setParameter('open_telemetry.http_client_metrics_enabled', $httpClientMetricsEnabled);
        $container->setParameter('open_telemetry.metrics_meter_name', $meterName);
        $container->setParameter('open_telemetry.http_client_metrics_excluded_hosts', $metrics['http_client']['excluded_hosts']);

        if ($metrics['enabled'] && $metrics['mailer']['enabled'] && $this->isMailerAvailable()) {
            // Priority 8 places this decorator INSIDE TraceableTransports (priority 0).
            // In Symfony decoration, higher priority = deeper nesting (see DecoratorServicePass).
            // Recording inside the active trace span scope enables SDK exemplar linkage.
            $meteredTransportsDef = new Definition(MeteredTransports::class);
            $meteredTransportsDef->setDecoratedService('mailer.transports', null, 8, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            $meteredTransportsDef->setArgument('$decorated', new Reference('.inner'));
            $meteredTransportsDef->setArgument('$meterName', $meterName);
            $meteredTransportsDef->addTag('kernel.reset', ['method' => 'reset']);
            self::register($container, 'open_telemetry.mailer.metered_transports', $meteredTransportsDef);
        }
    }

    /**
     * Registers a service under its bundle-alias id, with the pre-4.1 class-name id as a private alias.
     */
    private static function register(ContainerBuilder $container, string $id, Definition $definition): void
    {
        $container->setDefinition($id, $definition);

        $class = $definition->getClass();
        if (null !== $class) {
            $container->setAlias($class, $id);
        }
    }

    private static function remove(ContainerBuilder $container, string $id, string ...$aliases): void
    {
        $container->removeDefinition($id);
        foreach ($aliases as $alias) {
            $container->removeAlias($alias);
        }
    }

    /**
     * The built-in long-running commands are always unioned in, so setting excluded_commands extends the defaults.
     *
     * @param array{enabled: bool, excluded_commands: list<string>, trace_long_running_commands: bool} $console
     *
     * @return list<string>
     */
    private function resolveExcludedCommands(array $console): array
    {
        if ($console['trace_long_running_commands']) {
            return array_values(array_unique($console['excluded_commands']));
        }

        return array_values(array_unique([...$console['excluded_commands'], ...ConsoleSubscriber::LONG_RUNNING_COMMANDS]));
    }

    private function containsEnvPlaceholder(mixed $value): bool
    {
        if (\is_string($value)) {
            return 1 === preg_match('/%env\([^)]*\)%/', $value);
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsEnvPlaceholder($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isMessengerAvailable(): bool
    {
        return interface_exists(MiddlewareInterface::class);
    }

    private function isHttpClientAvailable(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }

    private function isDoctrineAvailable(): bool
    {
        return interface_exists(DoctrineMiddleware::class);
    }

    private function isCacheAvailable(): bool
    {
        return interface_exists(\Symfony\Contracts\Cache\CacheInterface::class);
    }

    private function isTwigAvailable(): bool
    {
        return class_exists(\Twig\Environment::class);
    }

    private function isMonologAvailable(): bool
    {
        return class_exists(\Monolog\Logger::class);
    }

    private function isSchedulerAvailable(): bool
    {
        return interface_exists(\Symfony\Component\Scheduler\ScheduleProviderInterface::class);
    }

    private function isMailerAvailable(): bool
    {
        return interface_exists(MailerInterface::class);
    }

    private function isXRayAvailable(): bool
    {
        return class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class);
    }
}
