<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Extends {@see TraceableCachePool} for tag-aware cache pools,
 * adding a span for invalidateTags().
 */
class TraceableTagAwareCachePool extends TraceableCachePool implements TagAwareCacheInterface, TagAwareAdapterInterface
{
    protected TagAwareCacheInterface $tagAwarePool;

    public function __construct(
        CacheItemPoolInterface $pool,
        string $tracerName,
        string $poolName,
    ) {
        if (!$pool instanceof TagAwareCacheInterface) {
            throw new \LogicException(\sprintf('Pool "%s" does not implement TagAwareCacheInterface.', $poolName));
        }

        parent::__construct($pool, $tracerName, $poolName);
        $this->tagAwarePool = $pool;
    }

    public function invalidateTags(array $tags): bool
    {
        if (!$this->isEnabled()) {
            return $this->tagAwarePool->invalidateTags($tags);
        }

        $pool = $this->tagAwarePool;

        return $this->traced(
            'cache.invalidate_tags',
            ['cache.tags' => array_values(array_map('strval', $tags))],
            static fn (): bool => $pool->invalidateTags($tags),
        );
    }
}
