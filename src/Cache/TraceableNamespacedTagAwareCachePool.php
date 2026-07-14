<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Extends {@see TraceableTagAwareCachePool} for pools that are also
 * namespaced (Symfony 7.3+ TagAwareAdapter), delegating withSubNamespace().
 *
 * Only instantiated by CacheTracingPass when NamespacedPoolInterface exists,
 * so referencing the interface here is safe on older symfony/cache-contracts.
 */
final class TraceableNamespacedTagAwareCachePool extends TraceableTagAwareCachePool implements NamespacedPoolInterface
{
    public function withSubNamespace(string $namespace): static
    {
        if (!$this->tagAwarePool instanceof NamespacedPoolInterface) {
            throw new \BadMethodCallException(\sprintf('Cannot call "%s::withSubNamespace()": the inner pool doesn\'t implement "%s".', get_debug_type($this->tagAwarePool), NamespacedPoolInterface::class));
        }

        $derived = $this->tagAwarePool->withSubNamespace($namespace);
        if (!$derived instanceof CacheItemPoolInterface) {
            throw new \LogicException(\sprintf('Derived pool %s must implement %s.', get_debug_type($derived), CacheItemPoolInterface::class));
        }

        $clone = clone $this;
        $clone->pool = $derived;
        $clone->tagAwarePool = $derived;

        return $clone;
    }
}
