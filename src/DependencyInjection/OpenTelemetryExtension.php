<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\DBAL\Driver\Middleware as DoctrineMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware as DoctrineTraceableMiddleware;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Monolog\TraceContextProcessor;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;

final class OpenTelemetryExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($config['messenger_enabled'] && $this->isMessengerAvailable()) {
            $container->prependExtensionConfig('framework', [
                'messenger' => [
                    'buses' => [
                        'messenger.bus.default' => [
                            'middleware' => [
                                OpenTelemetryMiddleware::class,
                            ],
                        ],
                    ],
                ],
            ]);
        }

        if ($config['log_export_enabled']) {
            if (!$container->hasExtension('monolog')) {
                throw new \LogicException(
                    'The "open_telemetry.log_export_enabled" option requires symfony/monolog-bundle to be installed and enabled. Run "composer require symfony/monolog-bundle" or set "log_export_enabled: false".'
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
            $handlerDef->setArgument('$level', $config['log_export_level']);
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
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.yaml');

        $tracerName = \is_string($config['tracer_name']) ? $config['tracer_name'] : 'opentelemetry-symfony';

        $container->getDefinition(Tracing::class)
            ->setArgument('$tracerName', $tracerName);

        $httpClientEnabled = $config['http_client_enabled'] && $this->isHttpClientAvailable();
        $container->setParameter('open_telemetry.http_client_enabled', $httpClientEnabled);
        $container->setParameter('open_telemetry.tracer_name', $tracerName);

        /** @var string[] $httpExcludedHosts */
        $httpExcludedHosts = $config['http_client_excluded_hosts'];
        $container->setParameter('open_telemetry.http_client_excluded_hosts', $httpExcludedHosts);

        if ($config['traces_enabled']) {
            $container->getDefinition(OpenTelemetrySubscriber::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedPaths', $config['excluded_paths'])
                ->setArgument('$recordClientIp', $config['record_client_ip'])
                ->setArgument('$errorStatusThreshold', $config['error_status_threshold']);
        } else {
            $container->removeDefinition(OpenTelemetrySubscriber::class);
        }

        if ($config['console_enabled'] && $this->isConsoleAvailable()) {
            $container->getDefinition(ConsoleSubscriber::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedCommands', $config['console_excluded_commands']);
        } else {
            $container->removeDefinition(ConsoleSubscriber::class);
        }

        if ($config['messenger_enabled'] && $this->isMessengerAvailable()) {
            $container->getDefinition(OpenTelemetryMiddleware::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$rootSpans', $config['messenger_root_spans']);
        } else {
            $container->removeDefinition(OpenTelemetryMiddleware::class);
        }

        if ($config['doctrine_enabled'] && $this->isDoctrineAvailable()) {
            $definition = new Definition(DoctrineTraceableMiddleware::class);
            $definition->setArgument('$tracerName', $tracerName);
            $definition->setArgument('$recordStatements', $config['doctrine_record_statements']);
            $definition->addTag('doctrine.middleware');
            $container->setDefinition(DoctrineTraceableMiddleware::class, $definition);
        }

        $cacheEnabled = $config['cache_enabled'] && $this->isCacheAvailable();
        $container->setParameter('open_telemetry.cache_enabled', $cacheEnabled);
        /** @var string[] $cacheExcludedPools */
        $cacheExcludedPools = $config['cache_excluded_pools'];
        $container->setParameter('open_telemetry.cache_excluded_pools', $cacheExcludedPools);

        if ($config['twig_enabled'] && $this->isTwigAvailable()) {
            /** @var string[] $twigExcluded */
            $twigExcluded = $config['twig_excluded_templates'];
            $twigExtDef = new Definition(OpenTelemetryTwigExtension::class);
            $twigExtDef->setArgument('$tracerName', $tracerName);
            $twigExtDef->setArgument('$excludedTemplates', $twigExcluded);
            $twigExtDef->addTag('twig.extension');
            $container->setDefinition(OpenTelemetryTwigExtension::class, $twigExtDef);
        }

        if ($config['monolog_enabled'] && $this->isMonologAvailable()) {
            $monologDef = new Definition(TraceContextProcessor::class);
            $monologDef->addTag('monolog.processor');
            $container->setDefinition(TraceContextProcessor::class, $monologDef);
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
}
