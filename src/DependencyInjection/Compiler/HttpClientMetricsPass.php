<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\HttpClient\MeteredHttpClient;

/**
 * Decorates all services tagged with 'http_client.client' and the default
 * 'http_client' service with {@see MeteredHttpClient} when metrics are
 * enabled for the HTTP client subsystem.
 *
 * Decoration priority is -8, higher than {@see HttpClientTracingPass}'s -16,
 * so the metrics decorator wraps the trace decorator. Recorded duration
 * therefore reflects what the app observes, including any overhead added by
 * the tracing layer underneath.
 */
final class HttpClientMetricsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('open_telemetry.http_client_metrics_enabled')) {
            return;
        }

        if (!$container->getParameter('open_telemetry.http_client_metrics_enabled')) {
            return;
        }

        $meterName = $container->getParameter('open_telemetry.metrics_meter_name');
        \assert(\is_string($meterName));

        /** @var string[] $excludedHosts */
        $excludedHosts = $container->hasParameter('open_telemetry.http_client_metrics_excluded_hosts')
            ? $container->getParameter('open_telemetry.http_client_metrics_excluded_hosts')
            : [];

        foreach ($this->findHttpClientServiceIds($container) as $clientId) {
            $decoratorId = $clientId . '.otel_metrics';
            $innerId = $decoratorId . '.inner';

            $decorator = new Definition(MeteredHttpClient::class);
            $decorator->setArgument('$client', new Reference($innerId));
            $decorator->setArgument('$meterName', $meterName);
            $decorator->setArgument('$excludedHosts', $excludedHosts);
            $decorator->setDecoratedService($clientId, $innerId, -8);

            $container->setDefinition($decoratorId, $decorator);
        }
    }

    /**
     * @return list<string>
     */
    private function findHttpClientServiceIds(ContainerBuilder $container): array
    {
        $ids = [];

        if ($container->has('http_client')) {
            $ids[] = 'http_client';
        }

        foreach ($container->findTaggedServiceIds('http_client.client') as $id => $tags) {
            if (!\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
