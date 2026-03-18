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
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Tracing;

final class OpenTelemetryExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if (!$this->isMessengerAvailable()) {
            return;
        }

        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (!$config['messenger_enabled']) {
            return;
        }

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

        if ($config['traces_enabled']) {
            $container->getDefinition(OpenTelemetrySubscriber::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedPaths', $config['excluded_paths'])
                ->setArgument('$recordClientIp', $config['record_client_ip'])
                ->setArgument('$errorStatusThreshold', $config['error_status_threshold']);
        } else {
            $container->removeDefinition(OpenTelemetrySubscriber::class);
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
}
