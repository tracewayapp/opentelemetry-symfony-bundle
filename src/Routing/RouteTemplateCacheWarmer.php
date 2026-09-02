<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Routing;

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Dumps the `route name => path template` map at cache warmup so
 * {@see RouteTemplateResolver} never rebuilds the route collection per request.
 *
 * {@see ConfigCache} writes it atomically and records the routing resources in a
 * `.meta` file next to it, which the resolver checks in debug.
 */
final class RouteTemplateCacheWarmer implements CacheWarmerInterface
{
    /**
     * @deprecated use {@see RouteTemplateCacheFile::FILE_NAME} instead
     */
    public const CACHE_FILE = RouteTemplateCacheFile::FILE_NAME;

    public function __construct(
        private readonly ?RouterInterface $router = null,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * Without the dump the resolver falls back to the router.
     */
    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return string[] classes to preload, always empty here
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (null === $this->router) {
            return [];
        }

        try {
            $collection = $this->router->getRouteCollection();

            // Written unconditionally: outside debug ConfigCache::isFresh() counts
            // any existing file as fresh, which would keep the last deploy's map.
            $cache = new ConfigCache(RouteTemplateCacheFile::pathIn($buildDir ?? $cacheDir), $this->debug);
            $cache->write(
                RouteTemplateCacheFile::render(self::buildRouteTemplates($collection)),
                $collection->getResources(),
            );
        } catch (\Throwable) {
            // Telemetry must never break a deploy: the resolver copes without the dump.
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function buildRouteTemplates(RouteCollection $collection): array
    {
        $routeTemplates = [];

        foreach ($collection as $name => $route) {
            $path = $route->getPath();

            if (!str_starts_with($path, '/')) {
                continue;
            }

            $routeTemplates[$name] = $path;
        }

        // Reproducible across builds.
        ksort($routeTemplates);

        return $routeTemplates;
    }
}
