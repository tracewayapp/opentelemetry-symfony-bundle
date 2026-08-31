<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the matched route's path template for http.route (e.g. /api/items/{id}).
 *
 * Sources, in order: the map dumped by {@see RouteTemplateCacheWarmer}, the router's
 * route collection, whole-segment substitution of the resolved route params. Returns
 * null for unrouted requests — per semconv http.route is only set on a match.
 */
final class RouteTemplateResolver implements ResetInterface
{
    /** @var array<string, string|null> route name => template, null when the router has no such route */
    private array $routerTemplates = [];

    /** @var array<string, string>|null the dumped map, null until first read */
    private ?array $warmedTemplates = null;

    public function __construct(
        private readonly ?RouterInterface $router = null,
        private readonly ?string $cacheDir = null,
        private readonly ?string $buildDir = null,
        private readonly bool $debug = false,
    ) {
    }

    public function resolve(Request $request): ?string
    {
        $routeName = $request->attributes->get('_route');
        if (!\is_string($routeName) || '' === $routeName) {
            return null;
        }

        return $this->getWarmedTemplates()[$routeName] ?? $this->fromRouter($routeName) ?? $this->synthesize($request);
    }

    /**
     * The dump is kept outside debug: it only changes on a deploy, which restarts
     * the workers anyway. In debug it is dropped so a rewritten dump is picked up.
     */
    public function reset(): void
    {
        $this->routerTemplates = [];

        if ($this->debug) {
            $this->warmedTemplates = null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function getWarmedTemplates(): array
    {
        if (null !== $this->warmedTemplates) {
            return $this->warmedTemplates;
        }

        // The warmer writes to the build dir when the kernel separates them.
        foreach ([$this->buildDir, $this->cacheDir] as $dir) {
            if (null === $dir) {
                continue;
            }

            // A route edit does not necessarily rebuild the container, so in debug
            // the dump can outlive its routes; the router rebuilds itself, we don't.
            if ($this->debug && !RouteTemplateCacheFile::isFreshIn($dir)) {
                continue;
            }

            $warmedTemplates = RouteTemplateCacheFile::load($dir);

            if (null !== $warmedTemplates) {
                return $this->warmedTemplates = $warmedTemplates;
            }
        }

        return $this->warmedTemplates = [];
    }

    /**
     * Rebuilds the route collection, so it must not run per request in prod —
     * that is what the warmer is for.
     */
    private function fromRouter(string $routeName): ?string
    {
        if (null === $this->router) {
            return null;
        }

        if (\array_key_exists($routeName, $this->routerTemplates)) {
            return $this->routerTemplates[$routeName];
        }

        try {
            $path = $this->router->getRouteCollection()->get($routeName)?->getPath();
        } catch (\Throwable) {
            $path = null;
        }

        if (null !== $path && !str_starts_with($path, '/')) {
            $path = null;
        }

        return $this->routerTemplates[$routeName] = $path;
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

        // A raw concrete path is not a template: emit no http.route instead.
        if (!$replaced) {
            return null;
        }

        return implode('/', $segments);
    }
}
