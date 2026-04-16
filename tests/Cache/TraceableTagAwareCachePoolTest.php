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
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Traceway\OpenTelemetryBundle\Cache\TraceableTagAwareCachePool;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableTagAwareCachePoolTest extends TestCase
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

    public function testInvalidateTagsCreatesSpan(): void
    {
        $inner = $this->createTagAwarePool();
        $pool = new TraceableTagAwareCachePool($inner, 'test-tracer', 'cache.app.taggable');

        $result = $pool->invalidateTags(['product', 'category']);

        self::assertTrue($result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('cache.invalidate_tags', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_INTERNAL, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        self::assertSame('cache.app.taggable', $attrs['cache.pool']);
        self::assertSame('product,category', $attrs['cache.tags']);
    }

    public function testInvalidateEmptyTagsArray(): void
    {
        $inner = $this->createTagAwarePool();
        $pool = new TraceableTagAwareCachePool($inner, 'test-tracer', 'cache.app.taggable');

        $pool->invalidateTags([]);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('', $spans[0]->getAttributes()->toArray()['cache.tags']);
    }

    public function testInvalidateTagsRecordsExceptionOnError(): void
    {
        $inner = $this->createFailingTagAwarePool(new \RuntimeException('Redis connection lost'));
        $pool = new TraceableTagAwareCachePool($inner, 'test-tracer', 'cache.app.taggable');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis connection lost');

        try {
            $pool->invalidateTags(['stale']);
        } finally {
            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            self::assertSame('Redis connection lost', $spans[0]->getStatus()->getDescription());

            $events = $spans[0]->getEvents();
            self::assertNotEmpty($events);
            self::assertSame('exception', $events[0]->getName());
        }
    }

    public function testConstructorRejectsNonTagAwarePool(): void
    {
        $inner = $this->createMock(CacheItemPoolInterface::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement TagAwareCacheInterface');

        new TraceableTagAwareCachePool($inner, 'test-tracer', 'cache.app');
    }

    public function testGetAndDeleteStillWork(): void
    {
        $inner = $this->createTagAwarePool();
        $pool = new TraceableTagAwareCachePool($inner, 'test-tracer', 'cache.app.taggable');

        $value = $pool->get('key', fn () => 'computed');
        self::assertSame('cached', $value);

        $deleted = $pool->delete('key');
        self::assertTrue($deleted);

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('cache.get', $spans[0]->getName());
        self::assertSame('cache.delete', $spans[1]->getName());
    }

    private function createTagAwarePool(): AdapterInterface&TagAwareCacheInterface
    {
        return new class implements AdapterInterface, CacheInterface, TagAwareCacheInterface {
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed { return 'cached'; }
            public function delete(string $key): bool { return true; }
            public function invalidateTags(array $tags): bool { return true; }
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
    }

    private function createFailingTagAwarePool(\Throwable $e): AdapterInterface&TagAwareCacheInterface
    {
        return new class($e) implements AdapterInterface, CacheInterface, TagAwareCacheInterface {
            public function __construct(private readonly \Throwable $e) {}
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed { throw $this->e; }
            public function delete(string $key): bool { throw $this->e; }
            public function invalidateTags(array $tags): bool { throw $this->e; }
            public function getItem(mixed $key): CacheItem { throw new \LogicException('Not implemented'); }
            public function getItems(array $keys = []): iterable { return []; }
            public function hasItem(mixed $key): bool { return false; }
            public function clear(string $prefix = ''): bool { throw $this->e; }
            public function deleteItem(string $key): bool { return true; }
            public function deleteItems(array $keys): bool { return true; }
            public function save(CacheItemInterface $item): bool { return true; }
            public function saveDeferred(CacheItemInterface $item): bool { return true; }
            public function commit(): bool { return true; }
        };
    }
}
