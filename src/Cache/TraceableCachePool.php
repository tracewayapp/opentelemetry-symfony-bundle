<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Cache;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates a Symfony cache pool to create INTERNAL spans for cache operations.
 *
 * Traces get(), delete(), and clear(). Other PSR-6 methods are
 * delegated without tracing to keep span volume manageable.
 *
 * Implements AdapterInterface so Symfony's profiler TraceableAdapter
 * can safely wrap this decorator in dev mode.
 */
class TraceableCachePool implements CacheInterface, AdapterInterface, ResetInterface
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    protected CacheItemPoolInterface $pool;

    public function __construct(
        CacheItemPoolInterface $pool,
        protected readonly string $tracerName,
        protected readonly string $poolName,
    ) {
        $this->pool = $pool;
    }

    /**
     * @param array<mixed>|null &$metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (!$this->pool instanceof CacheInterface) {
            throw new \LogicException(\sprintf('Pool "%s" (%s) must implement %s.', $this->poolName, $this->pool::class, CacheInterface::class));
        }

        if (!$this->isEnabled()) {
            return $this->pool->get($key, $callback, $beta, $metadata);
        }

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
        if (!$this->pool instanceof CacheInterface) {
            throw new \LogicException(\sprintf('Pool "%s" (%s) must implement %s.', $this->poolName, $this->pool::class, CacheInterface::class));
        }

        if (!$this->isEnabled()) {
            return $this->pool->delete($key);
        }

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

    public function getItem(mixed $key): CacheItem
    {
        $item = $this->pool->getItem($key);

        return $item instanceof CacheItem ? $item : throw new \LogicException('Expected CacheItem.');
    }

    /**
     * @return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        /** @var iterable<string, CacheItem> */
        return $this->pool->getItems($keys);
    }

    public function hasItem(mixed $key): bool
    {
        return $this->pool->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        if (!$this->isEnabled()) {
            return $this->pool instanceof AdapterInterface
                ? $this->pool->clear($prefix)
                : $this->pool->clear();
        }

        $span = $this->getTracer()
            ->spanBuilder('cache.clear')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('cache.pool', $this->poolName)
            ->startSpan();

        try {
            $result = $this->pool instanceof AdapterInterface
                ? $this->pool->clear($prefix)
                : $this->pool->clear();

            return $result;
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

    public function reset(): void
    {
        $this->tracer = null;
        $this->enabled = null;

        if ($this->pool instanceof ResetInterface) {
            $this->pool->reset();
        }
    }

    protected function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    protected function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }
}
