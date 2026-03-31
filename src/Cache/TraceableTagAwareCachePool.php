<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Cache;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Extends {@see TraceableCachePool} for tag-aware cache pools,
 * adding a span for invalidateTags().
 */
final class TraceableTagAwareCachePool extends TraceableCachePool implements TagAwareCacheInterface
{
    private readonly TagAwareCacheInterface $tagAwarePool;

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

        $span = $this->getTracer()
            ->spanBuilder('cache.invalidate_tags')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('cache.pool', $this->poolName)
            ->setAttribute('cache.tags', implode(',', array_map('strval', $tags)))
            ->startSpan();

        try {
            return $this->tagAwarePool->invalidateTags($tags);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }
}
