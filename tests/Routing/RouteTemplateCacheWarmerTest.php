<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateCacheFile;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateCacheWarmer;

final class RouteTemplateCacheWarmerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/otel-warmer-test-'.uniqid('', true);
        mkdir($this->cacheDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testDumpIsSortedByRouteName(): void
    {
        $collection = new RouteCollection();
        $collection->add('z_route', new Route('/z/{id}'));
        $collection->add('a_route', new Route('/a'));

        (new RouteTemplateCacheWarmer($this->router($collection)))->warmUp($this->cacheDir);

        self::assertSame(
            ['a_route' => '/a', 'z_route' => '/z/{id}'],
            RouteTemplateCacheFile::load($this->cacheDir),
        );
    }

    public function testBuildDirWinsOverCacheDir(): void
    {
        $buildDir = $this->cacheDir.'/build';
        mkdir($buildDir);

        $collection = new RouteCollection();
        $collection->add('a_route', new Route('/a'));

        (new RouteTemplateCacheWarmer($this->router($collection)))->warmUp($this->cacheDir, $buildDir);

        self::assertSame(['a_route' => '/a'], RouteTemplateCacheFile::load($buildDir));
        self::assertNull(RouteTemplateCacheFile::load($this->cacheDir));

        @unlink(RouteTemplateCacheFile::pathIn($buildDir));
        @unlink(RouteTemplateCacheFile::pathIn($buildDir).'.meta');
        @rmdir($buildDir);
    }

    public function testDebugModeRecordsRoutingResourcesForInvalidation(): void
    {
        $resource = tempnam(sys_get_temp_dir(), 'otel-routes');
        self::assertIsString($resource);

        $collection = new RouteCollection();
        $collection->add('a_route', new Route('/a'));
        $collection->addResource(new FileResource($resource));

        (new RouteTemplateCacheWarmer($this->router($collection), true))->warmUp($this->cacheDir);

        $meta = RouteTemplateCacheFile::pathIn($this->cacheDir).'.meta';
        self::assertFileExists($meta, 'debug builds must track routing resources so the dump is rebuilt when routes change');

        @unlink($resource);
    }

    public function testWarmupOverAWarmCacheDirRefreshesTheDump(): void
    {
        $stale = new RouteCollection();
        $stale->add('a_route', new Route('/a'));
        (new RouteTemplateCacheWarmer($this->router($stale)))->warmUp($this->cacheDir);

        $current = new RouteCollection();
        $current->add('a_route', new Route('/api/a'));
        (new RouteTemplateCacheWarmer($this->router($current)))->warmUp($this->cacheDir);

        self::assertSame(
            ['a_route' => '/api/a'],
            RouteTemplateCacheFile::load($this->cacheDir),
            'outside debug ConfigCache counts an existing file as fresh, so the write must not be gated on isFresh()',
        );
    }

    public function testWithoutRouterNothingIsWritten(): void
    {
        (new RouteTemplateCacheWarmer())->warmUp($this->cacheDir);

        self::assertFileDoesNotExist(RouteTemplateCacheFile::pathIn($this->cacheDir));
    }

    public function testThrowingRouterDoesNotBreakWarmup(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willThrowException(new \RuntimeException('no collection'));

        self::assertSame([], (new RouteTemplateCacheWarmer($router))->warmUp($this->cacheDir));
    }

    public function testWarmerIsOptional(): void
    {
        self::assertTrue((new RouteTemplateCacheWarmer())->isOptional());
    }

    private function router(RouteCollection $collection): RouterInterface
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return $router;
    }
}
