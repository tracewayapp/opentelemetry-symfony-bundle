<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Cache;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Decorates a Symfony cache pool to create INTERNAL spans for cache operations.
 *
 * Traces get(), delete(), and clear(). Other PSR-6 methods are
 * delegated without tracing to keep span volume manageable.
 *
 * Implements AdapterInterface so Symfony's profiler TraceableAdapter
 * can safely wrap this decorator in dev mode.
 */
class TraceableCachePool implements CacheInterface, AdapterInterface, PruneableInterface, ResetInterface
{
    private ?TracerInterface $tracer = null;

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
        $pool = $this->pool;

        return $this->traced('cache.get', ['cache.key' => $key], static function (SpanInterface $span) use ($pool, $key, $wrappedCallback, $beta, &$metadata, &$hit): mixed {
            $result = $pool->get($key, $wrappedCallback, $beta, $metadata);
            $span->setAttribute('cache.hit', $hit);

            return $result;
        });
    }

    public function delete(string $key): bool
    {
        if (!$this->pool instanceof CacheInterface) {
            throw new \LogicException(\sprintf('Pool "%s" (%s) must implement %s.', $this->poolName, $this->pool::class, CacheInterface::class));
        }

        if (!$this->isEnabled()) {
            return $this->pool->delete($key);
        }

        $pool = $this->pool;

        return $this->traced('cache.delete', ['cache.key' => $key], static fn (): bool => $pool->delete($key));
    }

    public function getItem(mixed $key): CacheItem
    {
        $item = $this->pool->getItem($key);

        // AdapterInterface::getItem() must return CacheItem; only Symfony adapters can be decorated.
        return $item instanceof CacheItem ? $item : throw new \LogicException(\sprintf('Pool "%s" (%s) returned %s; decorated pools must be Symfony cache adapters producing %s.', $this->poolName, $this->pool::class, get_debug_type($item), CacheItem::class));
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

        $pool = $this->pool;

        return $this->traced('cache.clear', [], static fn (): bool => $pool instanceof AdapterInterface
            ? $pool->clear($prefix)
            : $pool->clear());
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

    public function prune(): bool
    {
        return $this->pool instanceof PruneableInterface ? $this->pool->prune() : false;
    }

    public function reset(): void
    {
        $this->tracer = null;

        if ($this->pool instanceof ResetInterface) {
            $this->pool->reset();
        }
    }

    /**
     * @template T
     *
     * @param non-empty-string               $spanName
     * @param array<non-empty-string, mixed> $attributes
     * @param \Closure(SpanInterface): T     $op
     *
     * @return T
     */
    protected function traced(string $spanName, array $attributes, \Closure $op): mixed
    {
        $span = $this->getTracer()
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('cache.pool', $this->poolName)
            ->setAttributes($attributes)
            ->startSpan();

        try {
            return $op($span);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setAttribute('error.type', ErrorTypeResolver::resolve($e));
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    protected function isEnabled(): bool
    {
        return $this->getTracer()->isEnabled();
    }

    protected function getTracer(): TracerInterface
    {
        if (null !== $this->tracer) {
            return $this->tracer;
        }

        $tracer = Globals::tracerProvider()->getTracer(
            $this->tracerName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );

        // Only memoize a live tracer, so a noop seen before SDK init isn't pinned.
        if ($tracer->isEnabled()) {
            $this->tracer = $tracer;
        }

        return $tracer;
    }
}
