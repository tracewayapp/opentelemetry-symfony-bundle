<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;

/**
 * Decorates all services tagged with 'http_client.client' (Symfony's tag for
 * scoped HTTP clients) and the default 'http_client' service with our
 * {@see TraceableHttpClient} wrapper.
 *
 * The decoration priority is set low (-16) so it wraps after Symfony's own
 * TraceableHttpClient (used by the profiler) but still captures the span.
 */
final class HttpClientTracingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('open_telemetry.http_client_enabled')) {
            return;
        }

        if (!$container->getParameter('open_telemetry.http_client_enabled')) {
            return;
        }

        $tracerName = $container->getParameter('open_telemetry.tracer_name');
        \assert(\is_string($tracerName));

        /** @var string[] $excludedHosts */
        $excludedHosts = $container->hasParameter('open_telemetry.http_client_excluded_hosts')
            ? $container->getParameter('open_telemetry.http_client_excluded_hosts')
            : [];

        $clientIds = $this->findHttpClientServiceIds($container);

        foreach ($clientIds as $clientId) {
            $decoratorId = $clientId . '.otel';
            $innerId = $decoratorId . '.inner';

            $decorator = new Definition(TraceableHttpClient::class);
            $decorator->setArgument('$client', new Reference($innerId));
            $decorator->setArgument('$tracerName', $tracerName);
            $decorator->setArgument('$excludedHosts', $excludedHosts);
            $decorator->setDecoratedService($clientId, $innerId, -16);

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
