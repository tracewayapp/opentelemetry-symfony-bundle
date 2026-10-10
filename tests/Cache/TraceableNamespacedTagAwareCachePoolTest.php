<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Traceway\OpenTelemetryBundle\Cache\TraceableNamespacedTagAwareCachePool;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableNamespacedTagAwareCachePoolTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        if (!interface_exists(NamespacedPoolInterface::class) || !is_a(TagAwareAdapter::class, NamespacedPoolInterface::class, true)) {
            self::markTestSkipped('Namespaced tag-aware pools need symfony/cache 7.3+.');
        }

        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        if (isset($this->exporter)) {
            $this->tearDownOTel();
        }
    }

    public function testWithSubNamespaceKeepsTracingAndTagsOnTheDerivedPool(): void
    {
        $pool = new TraceableNamespacedTagAwareCachePool(new TagAwareAdapter(new ArrayAdapter()), 'test-tracer', 'cache.app');

        $users = $pool->withSubNamespace('users');

        self::assertInstanceOf(TraceableNamespacedTagAwareCachePool::class, $users);
        self::assertNotSame($pool, $users);

        $users->get('k', static fn (): string => 'users-value');
        self::assertSame('users-value', $users->get('k', static fn (): string => 'recomputed'));
        self::assertSame('root-value', $pool->get('k', static fn (): string => 'root-value'), 'the sub-namespace does not leak into the parent');

        $users->invalidateTags(['t']);
        self::assertContains('cache.get', array_map(static fn ($s): string => $s->getName(), $this->exporter->getSpans()));
    }
}
