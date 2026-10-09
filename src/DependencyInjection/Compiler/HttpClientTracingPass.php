<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\HttpClient\ResendCountingHttpClient;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;

/**
 * Decorates all services tagged with 'http_client.client' (Symfony's tag for
 * scoped HTTP clients) and the default 'http_client' service with our
 * {@see TraceableHttpClient} wrapper.
 *
 * A higher decoration priority sits closer to the transport. The tracer goes
 * at 64, inside Symfony's RetryableHttpClient (25; 10 on 6.4), ScopingHttpClient
 * and UriTemplateHttpClient, so each retried attempt is its own span and url.full
 * is the URL actually sent. A {@see ResendCountingHttpClient} goes at -16, outside
 * the retry wrapper, so the attempts share one counter for http.request.resend_count.
 */
final class HttpClientTracingPass implements CompilerPassInterface
{
    public const TRACER_PRIORITY = 64;
    public const RESEND_COUNTER_PRIORITY = -16;

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
            $decoratorId = $clientId.'.otel';
            $innerId = $decoratorId.'.inner';

            $decorator = new Definition(TraceableHttpClient::class);
            $decorator->setArgument('$client', new Reference($innerId));
            $decorator->setArgument('$tracerName', $tracerName);
            $decorator->setArgument('$excludedHosts', $excludedHosts);
            $decorator->setDecoratedService($clientId, $innerId, self::TRACER_PRIORITY);
            $decorator->addTag('kernel.reset', ['method' => 'reset']);

            $container->setDefinition($decoratorId, $decorator);

            $counter = new Definition(ResendCountingHttpClient::class);
            $counter->setArgument('$client', new Reference($clientId.'.otel_resend.inner'));
            $counter->setDecoratedService($clientId, $clientId.'.otel_resend.inner', self::RESEND_COUNTER_PRIORITY);
            $container->setDefinition($clientId.'.otel_resend', $counter);
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
