<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection;

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
use Doctrine\DBAL\Driver\Middleware as DoctrineMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredMiddleware as DoctrineMeteredMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware as DoctrineTraceableMiddleware;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetryMetricsSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\SchedulerSubscriber;
use Traceway\OpenTelemetryBundle\Mailer\MeteredTransports;
use Traceway\OpenTelemetryBundle\Mailer\TraceableMailer;
use Traceway\OpenTelemetryBundle\Mailer\TraceableTransports;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistry;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistryInterface;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Monolog\TraceContextProcessor;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;

final class OpenTelemetryExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        /** @var array{traces: array{messenger: array{enabled: bool}}, metrics: array{enabled: bool, messenger: array{enabled: bool}}, logs: array{export: array{enabled: bool, level: string, capture_code_attributes: bool, unprefixed_attributes: bool}}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($this->isMessengerAvailable()) {
            $middlewares = [];
            if ($config['traces']['messenger']['enabled']) {
                $middlewares[] = OpenTelemetryMiddleware::class;
            }
            $metrics = $config['metrics'];
            if ($metrics['enabled'] && $metrics['messenger']['enabled']) {
                $middlewares[] = OpenTelemetryMetricsMiddleware::class;
            }
            if ([] !== $middlewares) {
                $container->prependExtensionConfig('framework', [
                    'messenger' => [
                        'buses' => [
                            'messenger.bus.default' => [
                                'middleware' => $middlewares,
                            ],
                        ],
                    ],
                ]);
            }
        }

        if ($config['logs']['export']['enabled']) {
            if (!$container->hasExtension('monolog')) {
                throw new \LogicException(
                    'The "open_telemetry.logs.export.enabled" option requires symfony/monolog-bundle to be installed and enabled. Run "composer require symfony/monolog-bundle" or set "logs.export.enabled: false".'
                );
            }

            $container->prependExtensionConfig('monolog', [
                'handlers' => [
                    'opentelemetry' => [
                        'type' => 'service',
                        'id' => OtelLogHandler::class,
                    ],
                ],
            ]);

            $handlerDef = new Definition(OtelLogHandler::class);
            $handlerDef->setArgument('$level', $config['logs']['export']['level']);
            $handlerDef->setArgument('$captureCodeAttributes', $config['logs']['export']['capture_code_attributes']);
            $handlerDef->setArgument('$unprefixedAttributes', $config['logs']['export']['unprefixed_attributes']);
            $handlerDef->setAutoconfigured(true);
            $container->setDefinition(OtelLogHandler::class, $handlerDef);

            $flushDef = new Definition(OtelLoggerFlushSubscriber::class);
            $flushDef->setAutoconfigured(true);
            $container->setDefinition(OtelLoggerFlushSubscriber::class, $flushDef);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{traces: array{enabled: bool, tracer_name: string, excluded_paths: list<string>, record_client_ip: bool, error_status_threshold: int, console: array{enabled: bool, excluded_commands: list<string>}, http_client: array{enabled: bool, excluded_hosts: list<string>}, messenger: array{enabled: bool, root_spans: bool}, doctrine: array{enabled: bool, record_statements: bool}, cache: array{enabled: bool, excluded_pools: list<string>}, twig: array{enabled: bool, excluded_templates: list<string>}, scheduler: array{enabled: bool}, mailer: array{enabled: bool, record_subject: bool}}, metrics: array{enabled: bool, meter_name: string, messenger: array{enabled: bool, excluded_queues: list<string>}, doctrine: array{enabled: bool}, http_server: array{enabled: bool, excluded_paths: list<string>}, http_client: array{enabled: bool, excluded_hosts: list<string>}, mailer: array{enabled: bool}}, logs: array{correlation: array{enabled: bool}, export: array{enabled: bool, level: string, capture_code_attributes: bool, unprefixed_attributes: bool}}} $config */
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.yaml');

        $sdk = $config['sdk'];

        $traces = $config['traces'];
        $tracerName = $traces['tracer_name'];

        $container->getDefinition(Tracing::class)
            ->setArgument('$tracerName', $tracerName);

        $httpClientEnabled = $traces['http_client']['enabled'] && $this->isHttpClientAvailable();
        $container->setParameter('open_telemetry.http_client_enabled', $httpClientEnabled);
        $container->setParameter('open_telemetry.tracer_name', $tracerName);

        /** @var string[] $httpExcludedHosts */
        $httpExcludedHosts = $traces['http_client']['excluded_hosts'];
        $container->setParameter('open_telemetry.http_client_excluded_hosts', $httpExcludedHosts);

        if ($sdk['enabled']) {
            $container->setParameter('open_telemetry.sdk.config', $sdk);
        }

        if ($traces['enabled']) {
            $container->getDefinition(OpenTelemetrySubscriber::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedPaths', $traces['excluded_paths'])
                ->setArgument('$recordClientIp', $traces['record_client_ip'])
                ->setArgument('$errorStatusThreshold', $traces['error_status_threshold']);
        } else {
            $container->removeDefinition(OpenTelemetrySubscriber::class);
        }

        if ($traces['console']['enabled'] && $this->isConsoleAvailable()) {
            $container->getDefinition(ConsoleSubscriber::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedCommands', $traces['console']['excluded_commands']);
        } else {
            $container->removeDefinition(ConsoleSubscriber::class);
        }

        $schedulerEnabled = $traces['scheduler']['enabled'] && $this->isSchedulerAvailable();

        if ($traces['messenger']['enabled'] && $this->isMessengerAvailable()) {
            $container->getDefinition(OpenTelemetryMiddleware::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$rootSpans', $traces['messenger']['root_spans'])
                ->setArgument('$excludeScheduledMessages', $schedulerEnabled);
        } else {
            $container->removeDefinition(OpenTelemetryMiddleware::class);
        }

        if ($schedulerEnabled) {
            $schedulerDef = new Definition(SchedulerSubscriber::class);
            $schedulerDef->setArgument('$tracerName', $tracerName);
            $schedulerDef->setAutoconfigured(true);
            $container->setDefinition(SchedulerSubscriber::class, $schedulerDef);
        }

        if ($traces['doctrine']['enabled'] && $this->isDoctrineAvailable()) {
            $definition = new Definition(DoctrineTraceableMiddleware::class);
            $definition->setArgument('$tracerName', $tracerName);
            $definition->setArgument('$recordStatements', $traces['doctrine']['record_statements']);
            $definition->addTag('doctrine.middleware');
            $container->setDefinition(DoctrineTraceableMiddleware::class, $definition);
        }

        $cacheEnabled = $traces['cache']['enabled'] && $this->isCacheAvailable();
        $container->setParameter('open_telemetry.cache_enabled', $cacheEnabled);
        /** @var string[] $cacheExcludedPools */
        $cacheExcludedPools = $traces['cache']['excluded_pools'];
        $container->setParameter('open_telemetry.cache_excluded_pools', $cacheExcludedPools);

        if ($traces['twig']['enabled'] && $this->isTwigAvailable()) {
            /** @var string[] $twigExcluded */
            $twigExcluded = $traces['twig']['excluded_templates'];
            $twigExtDef = new Definition(OpenTelemetryTwigExtension::class);
            $twigExtDef->setArgument('$tracerName', $tracerName);
            $twigExtDef->setArgument('$excludedTemplates', $twigExcluded);
            $twigExtDef->addTag('twig.extension');
            $container->setDefinition(OpenTelemetryTwigExtension::class, $twigExtDef);
        }

        if ($traces['mailer']['enabled'] && $this->isMailerAvailable()) {
            $mailerDef = new Definition(TraceableMailer::class);
            $mailerDef->setDecoratedService('mailer.mailer', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            $mailerDef->setArgument('$decorated', new Reference('.inner'));
            $mailerDef->setArgument('$tracerName', $tracerName);
            $mailerDef->setArgument('$recordSubject', $traces['mailer']['record_subject']);
            $container->setDefinition(TraceableMailer::class, $mailerDef);

            $transportsDef = new Definition(TraceableTransports::class);
            $transportsDef->setDecoratedService('mailer.transports', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            $transportsDef->setArgument('$decorated', new Reference('.inner'));
            $transportsDef->setArgument('$tracerName', $tracerName);
            $container->setDefinition(TraceableTransports::class, $transportsDef);
        }

        if ($config['logs']['correlation']['enabled'] && $this->isMonologAvailable()) {
            $monologDef = new Definition(TraceContextProcessor::class);
            $monologDef->addTag('monolog.processor');
            $container->setDefinition(TraceContextProcessor::class, $monologDef);
        }

        $metrics = $config['metrics'];
        $meterName = $metrics['meter_name'];

        if ($metrics['enabled']) {
            $container->getDefinition(MeterRegistry::class)
                ->setArgument('$meterName', $meterName);
        } else {
            $container->removeDefinition(MeterRegistry::class);
            $container->removeAlias(MeterRegistryInterface::class);
        }

        if ($metrics['enabled'] && $metrics['messenger']['enabled'] && $this->isMessengerAvailable()) {
            $container->getDefinition(OpenTelemetryMetricsMiddleware::class)
                ->setArgument('$meterName', $meterName)
                ->setArgument('$excludedQueues', $metrics['messenger']['excluded_queues']);
        } else {
            $container->removeDefinition(OpenTelemetryMetricsMiddleware::class);
        }

        if ($metrics['enabled'] && $metrics['doctrine']['enabled'] && $this->isDoctrineAvailable()) {
            $definition = new Definition(DoctrineMeteredMiddleware::class);
            $definition->setArgument('$meterName', $meterName);
            $definition->addTag('doctrine.middleware');
            $container->setDefinition(DoctrineMeteredMiddleware::class, $definition);
        }

        if ($metrics['enabled'] && $metrics['http_server']['enabled']) {
            $container->getDefinition(OpenTelemetryMetricsSubscriber::class)
                ->setArgument('$meterName', $meterName)
                ->setArgument('$excludedPaths', $metrics['http_server']['excluded_paths']);
        } else {
            $container->removeDefinition(OpenTelemetryMetricsSubscriber::class);
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
            $container->setDefinition(MeteredTransports::class, $meteredTransportsDef);
        }
    }

    private function isConsoleAvailable(): bool
    {
        return class_exists(\Symfony\Component\Console\ConsoleEvents::class);
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
}
