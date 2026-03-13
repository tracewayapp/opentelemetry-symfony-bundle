<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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

        $container->getDefinition(Tracing::class)
            ->setArgument('$tracerName', $config['tracer_name']);

        $httpClientEnabled = $config['http_client_enabled'] && $this->isHttpClientAvailable();
        $container->setParameter('open_telemetry.http_client_enabled', $httpClientEnabled);
        $container->setParameter('open_telemetry.tracer_name', $config['tracer_name']);

        if ($config['traces_enabled']) {
            $container->getDefinition(OpenTelemetrySubscriber::class)
                ->setArgument('$tracerName', $config['tracer_name'])
                ->setArgument('$excludedPaths', $config['excluded_paths'])
                ->setArgument('$recordClientIp', $config['record_client_ip'])
                ->setArgument('$errorStatusThreshold', $config['error_status_threshold']);
        } else {
            $container->removeDefinition(OpenTelemetrySubscriber::class);
        }

        if ($config['messenger_enabled'] && $this->isMessengerAvailable()) {
            $container->getDefinition(OpenTelemetryMiddleware::class)
                ->setArgument('$tracerName', $config['tracer_name'])
                ->setArgument('$rootSpans', $config['messenger_root_spans']);
        } else {
            $container->removeDefinition(OpenTelemetryMiddleware::class);
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
}
