<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the matched route's path template for http.route (e.g. /api/items/{id}).
 *
 * Primary source is the router's route collection (the real compiled template).
 * Fallback is whole-segment substitution of resolved route params into the
 * concrete path, which cannot corrupt static segments the way substring
 * replacement could. Returns null for unrouted requests: per semconv,
 * http.route is only set when a route actually matched.
 */
final class RouteTemplateResolver implements ResetInterface
{
    /** @var array<string, string|null> */
    private array $templateCache = [];

    public function __construct(private readonly ?RouterInterface $router = null)
    {
    }

    public function resolve(Request $request): ?string
    {
        $routeName = $request->attributes->get('_route');
        if (!\is_string($routeName) || '' === $routeName) {
            return null;
        }

        return $this->fromRouter($routeName) ?? $this->synthesize($request);
    }

    public function reset(): void
    {
        $this->templateCache = [];
    }

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

    private function synthesize(Request $request): string
    {
        $path = $request->getPathInfo();
        $routeParams = $request->attributes->get('_route_params');

        if (!\is_array($routeParams) || [] === $routeParams) {
            return $path;
        }

        $segments = explode('/', $path);

        foreach ($routeParams as $name => $value) {
            if ((!\is_string($value) && !\is_int($value)) || '' === (string) $value) {
                continue;
            }

            foreach ($segments as $i => $segment) {
                if ($segment === (string) $value) {
                    $segments[$i] = '{' . $name . '}';
                    break;
                }
            }
        }

        return implode('/', $segments);
    }
}
