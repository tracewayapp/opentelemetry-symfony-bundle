<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Cache;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Decorates a Symfony cache pool to create INTERNAL spans for cache operations.
 *
 * Traces get(), delete(), and clear(). Other PSR-6 methods are
 * delegated without tracing to keep span volume manageable.
 */
class TraceableCachePool implements CacheInterface, CacheItemPoolInterface
{
    private ?TracerInterface $tracer = null;

    /** @var CacheItemPoolInterface&CacheInterface */
    private readonly CacheItemPoolInterface $pool;

    public function __construct(
        CacheItemPoolInterface $pool,
        protected readonly string $tracerName,
        protected readonly string $poolName,
    ) {
        if (!$pool instanceof CacheInterface) {
            throw new \LogicException(\sprintf(
                'Pool "%s" (%s) must implement %s.',
                $poolName,
                $pool::class,
                CacheInterface::class,
            ));
        }

        $this->pool = $pool;
    }

    /**
     * @param array<mixed>|null &$metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        $hit = true;
        $wrappedCallback = static function (ItemInterface $item, bool &$save) use ($callback, &$hit): mixed {
            $hit = false;

            return $callback($item, $save);
        };

        $span = $this->getTracer()
            ->spanBuilder(\sprintf('cache.get %s', $key))
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('cache.key', $key)
            ->setAttribute('cache.pool', $this->poolName)
            ->startSpan();

        try {
            $result = $this->pool->get($key, $wrappedCallback, $beta, $metadata);
            $span->setAttribute('cache.hit', $hit);

            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    public function delete(string $key): bool
    {
        $span = $this->getTracer()
            ->spanBuilder(\sprintf('cache.delete %s', $key))
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('cache.key', $key)
            ->setAttribute('cache.pool', $this->poolName)
            ->startSpan();

        try {
            return $this->pool->delete($key);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        /** @var iterable<string, CacheItemInterface> */
        return $this->pool->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    public function clear(): bool
    {
        $span = $this->getTracer()
            ->spanBuilder('cache.clear')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('cache.pool', $this->poolName)
            ->startSpan();

        try {
            return $this->pool->clear();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->pool->commit();
    }

    protected function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }
}
