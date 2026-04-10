<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Traceway\OpenTelemetryBundle\Cache\TraceableCachePool;
use Traceway\OpenTelemetryBundle\Cache\TraceableNamespacedCachePool;
use Traceway\OpenTelemetryBundle\Cache\TraceableTagAwareCachePool;

/**
 * Decorates all services tagged with 'cache.pool' with our tracing wrapper.
 *
 * Tag-aware pools get {@see TraceableTagAwareCachePool}, namespaced pools
 * (Symfony 7.3+) get {@see TraceableNamespacedCachePool}, and others get
 * {@see TraceableCachePool}. Decoration priority -32 ensures we wrap
 * after Symfony's own TraceableAdapter (profiler) at -16.
 */
final class CacheTracingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('open_telemetry.cache_enabled')) {
            return;
        }

        if (!$container->getParameter('open_telemetry.cache_enabled')) {
            return;
        }

        $tracerName = $container->getParameter('open_telemetry.tracer_name');
        \assert(\is_string($tracerName));

        /** @var string[] $excludedPools */
        $excludedPools = $container->hasParameter('open_telemetry.cache_excluded_pools')
            ? $container->getParameter('open_telemetry.cache_excluded_pools')
            : [];

        foreach ($container->findTaggedServiceIds('cache.pool') as $id => $tags) {
            $definition = $container->getDefinition($id);

            if ($definition->isAbstract() || \in_array($id, $excludedPools, true)) {
                continue;
            }

            $firstTag = $tags[0];
            \assert(\is_array($firstTag));
            $poolName = \is_string($firstTag['name'] ?? null) ? $firstTag['name'] : $id;
            $class = $definition->getClass();

            $isTagAware = null !== $class && is_subclass_of($class, TagAwareCacheInterface::class);
            $isNamespaced = !$isTagAware
                && interface_exists(NamespacedPoolInterface::class)
                && null !== $class
                && is_subclass_of($class, NamespacedPoolInterface::class);

            if ($isTagAware) {
                $decoratorClass = TraceableTagAwareCachePool::class;
            } elseif ($isNamespaced) {
                $decoratorClass = TraceableNamespacedCachePool::class;
            } else {
                $decoratorClass = TraceableCachePool::class;
            }
            $decoratorId = $id . '.otel';
            $innerId = $decoratorId . '.inner';

            $decorator = new Definition($decoratorClass);
            $decorator->setArgument('$pool', new Reference($innerId));
            $decorator->setArgument('$tracerName', $tracerName);
            $decorator->setArgument('$poolName', $poolName);
            $decorator->setDecoratedService($id, $innerId, -32);

            $container->setDefinition($decoratorId, $decorator);
        }
    }
}
