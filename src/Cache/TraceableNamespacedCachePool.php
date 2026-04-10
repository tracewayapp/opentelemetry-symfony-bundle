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
    private readonly NamespacedPoolInterface $namespacedPool;

    public function __construct(
        CacheItemPoolInterface $pool,
        string $tracerName,
        string $poolName,
    ) {
        if (!$pool instanceof NamespacedPoolInterface) {
            throw new \LogicException(\sprintf('Pool "%s" does not implement NamespacedPoolInterface.', $poolName));
        }

        parent::__construct($pool, $tracerName, $poolName);
        $this->namespacedPool = $pool;
    }

    public function withSubNamespace(string $namespace): static
    {
        $inner = $this->namespacedPool->withSubNamespace($namespace);

        /** @var static */
        return new self($inner, $this->tracerName, $this->poolName);
    }
}
