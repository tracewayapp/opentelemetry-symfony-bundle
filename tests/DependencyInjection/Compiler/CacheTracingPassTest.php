<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Traceway\OpenTelemetryBundle\Cache\TraceableCachePool;
use Traceway\OpenTelemetryBundle\Cache\TraceableNamespacedCachePool;
use Traceway\OpenTelemetryBundle\Cache\TraceableTagAwareCachePool;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\CacheTracingPass;

final class CacheTracingPassTest extends TestCase
{
    public function testDecoratesCachePool(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.cache_enabled', true);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');

        $poolDef = new Definition(FilesystemAdapter::class);
        $poolDef->addTag('cache.pool', ['name' => 'cache.app']);
        $container->setDefinition('cache.app', $poolDef);

        $pass = new CacheTracingPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('cache.app.otel'));

        $decorator = $container->getDefinition('cache.app.otel');
        $expectedClass = interface_exists(NamespacedPoolInterface::class) && is_subclass_of(FilesystemAdapter::class, NamespacedPoolInterface::class)
            ? TraceableNamespacedCachePool::class
            : TraceableCachePool::class;
        self::assertSame($expectedClass, $decorator->getClass());
        self::assertSame('test-tracer', $decorator->getArgument('$tracerName'));
        self::assertSame('cache.app', $decorator->getArgument('$poolName'));
    }

    public function testDecoratesTagAwarePool(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.cache_enabled', true);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');

        $poolDef = new Definition(TagAwareAdapter::class);
        $poolDef->addTag('cache.pool', ['name' => 'cache.app.taggable']);
        $container->setDefinition('cache.app.taggable', $poolDef);

        $pass = new CacheTracingPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('cache.app.taggable.otel'));

        $decorator = $container->getDefinition('cache.app.taggable.otel');
        self::assertSame(TraceableTagAwareCachePool::class, $decorator->getClass());
    }

    public function testSkipsWhenDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.cache_enabled', false);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');

        $poolDef = new Definition(FilesystemAdapter::class);
        $poolDef->addTag('cache.pool');
        $container->setDefinition('cache.app', $poolDef);

        $pass = new CacheTracingPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition('cache.app.otel'));
    }

    public function testSkipsWhenParameterMissing(): void
    {
        $container = new ContainerBuilder();

        $poolDef = new Definition(FilesystemAdapter::class);
        $poolDef->addTag('cache.pool');
        $container->setDefinition('cache.app', $poolDef);

        $pass = new CacheTracingPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition('cache.app.otel'));
    }

    public function testSkipsExcludedPools(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.cache_enabled', true);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');
        $container->setParameter('open_telemetry.cache_excluded_pools', ['cache.system']);

        $systemDef = new Definition(FilesystemAdapter::class);
        $systemDef->addTag('cache.pool', ['name' => 'cache.system']);
        $container->setDefinition('cache.system', $systemDef);

        $appDef = new Definition(FilesystemAdapter::class);
        $appDef->addTag('cache.pool', ['name' => 'cache.app']);
        $container->setDefinition('cache.app', $appDef);

        $pass = new CacheTracingPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition('cache.system.otel'));
        self::assertTrue($container->hasDefinition('cache.app.otel'));
    }

    public function testUsesServiceIdAsPoolNameFallback(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.cache_enabled', true);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');

        $poolDef = new Definition(FilesystemAdapter::class);
        $poolDef->addTag('cache.pool');
        $container->setDefinition('app.my_custom_cache', $poolDef);

        $pass = new CacheTracingPass();
        $pass->process($container);

        $decorator = $container->getDefinition('app.my_custom_cache.otel');
        self::assertSame('app.my_custom_cache', $decorator->getArgument('$poolName'));
    }
}
