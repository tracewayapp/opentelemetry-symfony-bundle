<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Extends {@see TraceableCachePool} for namespaced cache pools,
 * delegating withSubNamespace() to the inner pool.
 */
final class TraceableNamespacedCachePool extends TraceableCachePool implements NamespacedPoolInterface
{
    public function __construct(
        CacheItemPoolInterface $pool,
        string $tracerName,
        string $poolName,
    ) {
        if (!$pool instanceof NamespacedPoolInterface) {
            throw new \LogicException(\sprintf('Pool "%s" does not implement NamespacedPoolInterface.', $poolName));
        }

        parent::__construct($pool, $tracerName, $poolName);
    }

    public function withSubNamespace(string $namespace): static
    {
        if (!$this->pool instanceof NamespacedPoolInterface) {
            throw new \BadMethodCallException(\sprintf('Cannot call "%s::withSubNamespace()": the inner pool doesn\'t implement "%s".', get_debug_type($this->pool), NamespacedPoolInterface::class));
        }

        $clone = clone $this;
        $clone->pool = $this->pool->withSubNamespace($namespace);

        return $clone;
    }
}
