<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Traceway\OpenTelemetryBundle\Cache\TraceableNamespacedCachePool;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableNamespacedCachePoolTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        if (!interface_exists(NamespacedPoolInterface::class)) {
            self::markTestSkipped('NamespacedPoolInterface not available.');
        }

        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testWithSubNamespaceReturnsNewInstance(): void
    {
        $inner = $this->createNamespacedPool();
        $pool = new TraceableNamespacedCachePool($inner, 'test-tracer', 'cache.app');

        $namespaced = $pool->withSubNamespace('users');

        self::assertInstanceOf(TraceableNamespacedCachePool::class, $namespaced);
        self::assertNotSame($pool, $namespaced);
    }

    public function testWithSubNamespaceDelegatesToInnerPool(): void
    {
        $inner = $this->createNamespacedPool();
        $pool = new TraceableNamespacedCachePool($inner, 'test-tracer', 'cache.app');

        $namespaced = $pool->withSubNamespace('users');

        $value = $namespaced->get('key', fn () => 'computed');
        self::assertSame('namespaced', $value);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('cache.get', $spans[0]->getName());
    }

    public function testConstructorRejectsNonNamespacedPool(): void
    {
        $inner = $this->createMock(CacheItemPoolInterface::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement NamespacedPoolInterface');

        new TraceableNamespacedCachePool($inner, 'test-tracer', 'cache.app');
    }

    private function createNamespacedPool(): AdapterInterface&NamespacedPoolInterface
    {
        return new class implements AdapterInterface, CacheInterface, NamespacedPoolInterface {
            private string $value = 'cached';
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed { return $this->value; }
            public function delete(string $key): bool { return true; }
            public function withSubNamespace(string $namespace): static { $clone = clone $this; $clone->value = 'namespaced'; return $clone; }
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
}
