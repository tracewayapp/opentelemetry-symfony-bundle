<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateCacheFile;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateCacheWarmer;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateResolver;

/**
 * Covers the debug-only check that keeps a dump written before a route edit
 * from feeding a stale http.route into telemetry.
 */
final class RouteTemplateFreshnessTest extends TestCase
{
    private string $cacheDir;
    private string $routesFile;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/otel-freshness-test-'.uniqid('', true);
        mkdir($this->cacheDir);

        $routesFile = tempnam(sys_get_temp_dir(), 'otel-routes');
        self::assertIsString($routesFile);
        $this->routesFile = $routesFile;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
        @unlink($this->routesFile);
    }

    public function testDebugIgnoresADumpOlderThanTheRoutingResources(): void
    {
        $this->warmDumpFor('/old/{id}');
        $this->touchRoutesFile();

        $resolver = new RouteTemplateResolver($this->routerFor('/new/{id}'), $this->cacheDir, null, true);

        self::assertSame('/new/{id}', $resolver->resolve($this->request()));
    }

    public function testDebugTrustsADumpNewerThanTheRoutingResources(): void
    {
        $this->warmDumpFor('/old/{id}');

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('getRouteCollection');

        $resolver = new RouteTemplateResolver($router, $this->cacheDir, null, true);

        self::assertSame('/old/{id}', $resolver->resolve($this->request()));
    }

    public function testOutsideDebugAStaleDumpIsStillUsed(): void
    {
        $this->warmDumpFor('/old/{id}');
        $this->touchRoutesFile();

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('getRouteCollection');

        $resolver = new RouteTemplateResolver($router, $this->cacheDir);

        self::assertSame('/old/{id}', $resolver->resolve($this->request()), 'freshness is a debug-only cost; in prod the deploy re-runs the warmer');
    }

    public function testDebugRereadsTheDumpAfterReset(): void
    {
        $this->warmDumpFor('/old/{id}');

        $resolver = new RouteTemplateResolver($this->routerFor('/new/{id}'), $this->cacheDir, null, true);
        self::assertSame('/old/{id}', $resolver->resolve($this->request()));

        // A rerun of the warmer (cache:clear in another process) must be picked up.
        $this->warmDumpFor('/rewritten/{id}');
        $resolver->reset();

        self::assertSame('/rewritten/{id}', $resolver->resolve($this->request()));
    }

    public function testDumpWithoutMetaIsNotTrustedInDebug(): void
    {
        file_put_contents(
            RouteTemplateCacheFile::pathIn($this->cacheDir),
            RouteTemplateCacheFile::render(['api_item_show' => '/old/{id}']),
        );

        $resolver = new RouteTemplateResolver($this->routerFor('/new/{id}'), $this->cacheDir, null, true);

        self::assertSame('/new/{id}', $resolver->resolve($this->request()), 'a dump that cannot be verified must not win over the router');
    }

    private function warmDumpFor(string $path): void
    {
        $collection = new RouteCollection();
        $collection->add('api_item_show', new Route($path));
        $collection->addResource(new FileResource($this->routesFile));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        (new RouteTemplateCacheWarmer($router, true))->warmUp($this->cacheDir);
    }

    private function touchRoutesFile(): void
    {
        // The meta file stores the dump's mtime; move the resource past it.
        touch($this->routesFile, time() + 10);
        clearstatcache(true, $this->routesFile);
    }

    private function routerFor(string $path): RouterInterface
    {
        $collection = new RouteCollection();
        $collection->add('api_item_show', new Route($path));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return $router;
    }

    private function request(): Request
    {
        $request = Request::create('/old/5', 'GET');
        $request->attributes->set('_route', 'api_item_show');

        return $request;
    }
}
