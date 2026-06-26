<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Routing;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Dumps a route-name to path-template map at cache warmup so
 * {@see RouteTemplateResolver} never rebuilds the route collection
 * during request handling (expensive and discouraged under PHP-FPM).
 */
final class RouteTemplateCacheWarmer implements CacheWarmerInterface
{
    public const CACHE_FILE = 'traceway_otel_route_templates.php';

    public function __construct(private readonly ?RouterInterface $router = null)
    {
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (null === $this->router) {
            return [];
        }

        $map = [];

        try {
            foreach ($this->router->getRouteCollection() as $name => $route) {
                $path = $route->getPath();
                if (str_starts_with($path, '/')) {
                    $map[$name] = $path;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        $file = rtrim($buildDir ?? $cacheDir, '/').'/'.self::CACHE_FILE;
        @file_put_contents($file, '<?php return '.var_export($map, true).';');

        return [];
    }
}
