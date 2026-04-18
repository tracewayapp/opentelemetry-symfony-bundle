<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Cache;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Cache\TraceableCachePool;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableCachePoolTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testGetCreatesSpanOnHit(): void
    {
        $inner = $this->createCachePool('cached-value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $result = $pool->get('my_key', fn () => 'computed');

        self::assertSame('cached-value', $result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('cache.get', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_INTERNAL, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('my_key', $attrs['cache.key']);
        self::assertSame('cache.app', $attrs['cache.pool']);
        self::assertTrue($attrs['cache.hit']);
    }

    public function testGetCreatesSpanOnMiss(): void
    {
        $inner = $this->createCachePool('computed-value', false);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $result = $pool->get('missing_key', fn () => 'computed-value');

        self::assertSame('computed-value', $result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertFalse($attrs['cache.hit']);
    }

    public function testGetRecordsExceptionOnError(): void
    {
        $inner = $this->createFailingCachePool(new \RuntimeException('Cache backend down'));
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache backend down');

        try {
            $pool->get('key', fn () => 'value');
        } finally {
            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            self::assertSame('Cache backend down', $spans[0]->getStatus()->getDescription());

            $events = $spans[0]->getEvents();
            self::assertNotEmpty($events);
            self::assertSame('exception', $events[0]->getName());
        }
    }

    public function testDeleteCreatesSpan(): void
    {
        $inner = $this->createCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $result = $pool->delete('old_key');

        self::assertTrue($result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('cache.delete', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_INTERNAL, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('old_key', $attrs['cache.key']);
        self::assertSame('cache.app', $attrs['cache.pool']);
    }

    public function testDeleteRecordsExceptionOnError(): void
    {
        $inner = $this->createFailingCachePool(new \RuntimeException('Delete failed'));
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Delete failed');

        try {
            $pool->delete('key');
        } finally {
            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame('cache.delete', $spans[0]->getName());
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            self::assertSame('Delete failed', $spans[0]->getStatus()->getDescription());

            $events = $spans[0]->getEvents();
            self::assertNotEmpty($events);
            self::assertSame('exception', $events[0]->getName());
        }
    }

    public function testClearCreatesSpan(): void
    {
        $inner = $this->createCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $result = $pool->clear();

        self::assertTrue($result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('cache.clear', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_INTERNAL, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('cache.app', $attrs['cache.pool']);
    }

    public function testClearRecordsExceptionOnError(): void
    {
        $inner = $this->createFailingCachePool(new \RuntimeException('Clear failed'));
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Clear failed');

        try {
            $pool->clear();
        } finally {
            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame('cache.clear', $spans[0]->getName());
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        }
    }

    public function testPsr6MethodsDelegateWithoutTracing(): void
    {
        $inner = $this->createCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $pool->hasItem('key');
        $pool->deleteItem('key');

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testGetRejectsPoolWithoutCacheInterface(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must implement');

        $inner = $this->createMock(CacheItemPoolInterface::class);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');
        $pool->get('key', fn () => 'value');
    }

    public function testDeleteRejectsPoolWithoutCacheInterface(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must implement');

        $inner = $this->createMock(CacheItemPoolInterface::class);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');
        $pool->delete('key');
    }

    public function testPsr6DelegationMethods(): void
    {
        $inner = $this->createCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        self::assertTrue($pool->deleteItems(['a', 'b']));
        self::assertTrue($pool->commit());
        self::assertEmpty($pool->getItems(['a']));
        self::assertEmpty($this->exporter->getSpans());
    }

    public function testSaveAndSaveDeferredDelegate(): void
    {
        $inner = $this->createCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $item = $this->createStub(CacheItemInterface::class);
        self::assertTrue($pool->save($item));
        self::assertTrue($pool->saveDeferred($item));
        self::assertEmpty($this->exporter->getSpans());
    }

    public function testResetClearsTracerAndResetsPool(): void
    {
        $inner = $this->createResettableCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $pool->get('key', fn () => 'value');
        self::assertCount(1, $this->exporter->getSpans());

        $pool->reset();

        $pool->get('key2', fn () => 'value');
        self::assertCount(2, $this->exporter->getSpans());
    }

    public function testResetWithNonResettablePool(): void
    {
        $inner = $this->createCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');

        $pool->reset();

        $pool->get('key', fn () => 'value');
        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testClearDelegatesToNonAdapterPool(): void
    {
        $inner = new class implements CacheItemPoolInterface, CacheInterface {
            public bool $cleared = false;
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed { return null; }
            public function delete(string $key): bool { return true; }
            public function getItem(mixed $key): CacheItem { throw new \LogicException('Not implemented'); }
            public function getItems(array $keys = []): iterable { return []; }
            public function hasItem(mixed $key): bool { return false; }
            public function clear(): bool { $this->cleared = true; return true; }
            public function deleteItem(string $key): bool { return true; }
            public function deleteItems(array $keys): bool { return true; }
            public function save(CacheItemInterface $item): bool { return true; }
            public function saveDeferred(CacheItemInterface $item): bool { return true; }
            public function commit(): bool { return true; }
        };

        $pool = new TraceableCachePool($inner, 'test-tracer', 'cache.app');
        $result = $pool->clear();

        self::assertTrue($result);
        self::assertTrue($inner->cleared);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('cache.clear', $spans[0]->getName());
    }

    public function testCustomTracerName(): void
    {
        $inner = $this->createCachePool('value', true);
        $pool = new TraceableCachePool($inner, 'my-app', 'cache.app');

        $pool->get('key', fn () => 'value');

        $spans = $this->exporter->getSpans();
        self::assertSame('my-app', $spans[0]->getInstrumentationScope()->getName());
    }

    /**
     * Creates a mock that implements AdapterInterface and CacheInterface.
     */
    private function createCachePool(mixed $returnValue, bool $isHit): AdapterInterface&CacheInterface
    {
        $mock = new class($returnValue, $isHit) implements AdapterInterface, CacheInterface {
            public function __construct(
                private readonly mixed $returnValue,
                private readonly bool $isHit,
            ) {}

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                if ($this->isHit) {
                    return $this->returnValue;
                }

                $item = new class implements ItemInterface {
                    public function getKey(): string { return ''; }
                    public function get(): mixed { return null; }
                    public function isHit(): bool { return false; }
                    public function set(mixed $value): static { return $this; }
                    public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                    public function expiresAfter(\DateInterval|int|null $time): static { return $this; }
                    public function tag(string|iterable $tags): static { return $this; }
                    public function getMetadata(): array { return []; }
                };

                $save = true;
                return $callback($item, $save);
            }

            public function delete(string $key): bool { return true; }
            public function getItem(mixed $key): CacheItem { throw new \LogicException('Not implemented'); }
            public function getItems(array $keys = []): iterable { return []; }
            public function hasItem(mixed $key): bool { return false; }
            public function clear(string $prefix = ''): bool { return true; }
            public function deleteItem(string $key): bool { return true; }
            public function deleteItems(array $keys): bool { return true; }
            public function save(CacheItemInterface $item): bool { return true; }
            public function saveDeferred(CacheItemInterface $item): bool { return true; }
            public function commit(): bool { return true; }
        };

        return $mock;
    }

    private function createResettableCachePool(mixed $returnValue, bool $isHit): AdapterInterface&CacheInterface&ResetInterface
    {
        $mock = new class($returnValue, $isHit) implements AdapterInterface, CacheInterface, ResetInterface {
            public bool $wasReset = false;
            public function __construct(
                private readonly mixed $returnValue,
                private readonly bool $isHit,
            ) {}

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return $this->isHit ? $this->returnValue : $callback(
                    new class implements ItemInterface {
                        public function getKey(): string { return ''; }
                        public function get(): mixed { return null; }
                        public function isHit(): bool { return false; }
                        public function set(mixed $value): static { return $this; }
                        public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                        public function expiresAfter(\DateInterval|int|null $time): static { return $this; }
                        public function tag(string|iterable $tags): static { return $this; }
                        public function getMetadata(): array { return []; }
                    },
                    $save = true,
                );
            }

            public function delete(string $key): bool { return true; }
            public function getItem(mixed $key): CacheItem { throw new \LogicException('Not implemented'); }
            public function getItems(array $keys = []): iterable { return []; }
            public function hasItem(mixed $key): bool { return false; }
            public function clear(string $prefix = ''): bool { return true; }
            public function deleteItem(string $key): bool { return true; }
            public function deleteItems(array $keys): bool { return true; }
            public function save(CacheItemInterface $item): bool { return true; }
            public function saveDeferred(CacheItemInterface $item): bool { return true; }
            public function commit(): bool { return true; }
            public function reset(): void { $this->wasReset = true; }
        };

        return $mock;
    }

    private function createFailingCachePool(\Throwable $exception): AdapterInterface&CacheInterface
    {
        $mock = new class($exception) implements AdapterInterface, CacheInterface {
            public function __construct(private readonly \Throwable $exception) {}

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw $this->exception;
            }

            public function delete(string $key): bool { throw $this->exception; }
            public function getItem(mixed $key): CacheItem { throw new \LogicException('Not implemented'); }
            public function getItems(array $keys = []): iterable { return []; }
            public function hasItem(mixed $key): bool { return false; }
            public function clear(string $prefix = ''): bool { throw $this->exception; }
            public function deleteItem(string $key): bool { return true; }
            public function deleteItems(array $keys): bool { return true; }
            public function save(CacheItemInterface $item): bool { return true; }
            public function saveDeferred(CacheItemInterface $item): bool { return true; }
            public function commit(): bool { return true; }
        };

        return $mock;
    }
}
