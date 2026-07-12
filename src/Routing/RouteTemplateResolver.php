<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the matched route's path template for http.route (e.g. /api/items/{id}).
 *
 * Primary source is the map dumped by {@see RouteTemplateCacheWarmer} (opcache-shared,
 * free at runtime). Without a warmed cache it falls back to the router's route
 * collection (rebuilds the collection — acceptable in dev / long-running workers
 * where it is memoized, avoided in prod by the warmer), then to whole-segment
 * substitution of resolved route params, which cannot corrupt static segments
 * the way substring replacement could. Returns null for unrouted requests:
 * per semconv, http.route is only set when a route actually matched.
 */
final class RouteTemplateResolver implements ResetInterface
{
    /** @var array<string, string|null> */
    private array $templateCache = [];

    /** @var array<string, string>|null */
    private ?array $warmedMap = null;
    private bool $warmedMapLoaded = false;

    public function __construct(
        private readonly ?RouterInterface $router = null,
        private readonly ?string $cacheDir = null,
        private readonly ?string $buildDir = null,
    ) {
    }

    public function resolve(Request $request): ?string
    {
        $routeName = $request->attributes->get('_route');
        if (!\is_string($routeName) || '' === $routeName) {
            return null;
        }

        return $this->fromWarmedMap($routeName) ?? $this->fromRouter($routeName) ?? $this->synthesize($request);
    }

    public function reset(): void
    {
        $this->templateCache = [];
    }

    private function fromWarmedMap(string $routeName): ?string
    {
        if (!$this->warmedMapLoaded) {
            $this->warmedMapLoaded = true;
            $this->warmedMap = $this->loadWarmedMap();
        }

        return $this->warmedMap[$routeName] ?? null;
    }

    /**
     * @return array<string, string>|null
     */
    private function loadWarmedMap(): ?array
    {
        // The warmer writes to the build dir when the kernel separates them.
        foreach ([$this->buildDir, $this->cacheDir] as $dir) {
            if (null === $dir) {
                continue;
            }

            $file = rtrim($dir, '/').'/'.RouteTemplateCacheWarmer::CACHE_FILE;
            if (!is_file($file)) {
                continue;
            }

            try {
                $map = require $file;
            } catch (\Throwable) {
                continue;
            }

            if (\is_array($map)) {
                /** @var array<string, string> $map */
                return $map;
            }
        }

        return null;
    }

    /**
     * Last-resort lookup: rebuilds the route collection when no warmed map
     * exists, so it must never run per-request in prod (the warmer prevents it).
     */
    private function fromRouter(string $routeName): ?string
    {
        if (null === $this->router) {
            return null;
        }

        if (\array_key_exists($routeName, $this->templateCache)) {
            return $this->templateCache[$routeName];
        }

        try {
            $path = $this->router->getRouteCollection()->get($routeName)?->getPath();
        } catch (\Throwable) {
            $path = null;
        }

        if (null !== $path && !str_starts_with($path, '/')) {
            $path = null;
        }

        return $this->templateCache[$routeName] = $path;
    }

    private function synthesize(Request $request): ?string
    {
        $path = $request->getPathInfo();
        $routeParams = $request->attributes->get('_route_params');

        if (!\is_array($routeParams) || [] === $routeParams) {
            return null;
        }

        $segments = explode('/', $path);
        $replaced = false;

        foreach ($routeParams as $name => $value) {
            if ((!\is_string($value) && !\is_int($value)) || '' === (string) $value) {
                continue;
            }

            foreach ($segments as $i => $segment) {
                if ($segment === (string) $value) {
                    $segments[$i] = '{'.$name.'}';
                    $replaced = true;
                    break;
                }
            }
        }

        // A raw concrete path is not a template; per semconv, emit no http.route instead.
        if (!$replaced) {
            return null;
        }

        return implode('/', $segments);
    }
}
