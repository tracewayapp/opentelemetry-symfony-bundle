<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateResolver;

final class RouteTemplateResolverTest extends TestCase
{
    public function testUnroutedRequestReturnsNull(): void
    {
        $resolver = new RouteTemplateResolver();
        $request = Request::create('/api/items/5', 'GET');

        self::assertNull($resolver->resolve($request));
    }

    public function testRouterTemplateIsPreferred(): void
    {
        $collection = new RouteCollection();
        $collection->add('api_item_show', new Route('/api/items/{id}.{_format}'));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $resolver = new RouteTemplateResolver($router);
        $request = Request::create('/api/items/5', 'GET');
        $request->attributes->set('_route', 'api_item_show');
        $request->attributes->set('_route_params', ['id' => '5']);

        self::assertSame('/api/items/{id}.{_format}', $resolver->resolve($request));
    }

    public function testSynthesisFallbackWhenRouteUnknownToRouter(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn(new RouteCollection());

        $resolver = new RouteTemplateResolver($router);
        $request = Request::create('/api/items/5', 'GET');
        $request->attributes->set('_route', 'api_item_show');
        $request->attributes->set('_route_params', ['id' => '5']);

        self::assertSame('/api/items/{id}', $resolver->resolve($request));
    }

    public function testSynthesisDoesNotCorruptStaticSegments(): void
    {
        $resolver = new RouteTemplateResolver();
        $request = Request::create('/api/items/a', 'GET');
        $request->attributes->set('_route', 'api_item_show');
        $request->attributes->set('_route_params', ['key' => 'a']);

        self::assertSame('/api/items/{key}', $resolver->resolve($request));
    }

    public function testSynthesisWithoutParamsReturnsPath(): void
    {
        $resolver = new RouteTemplateResolver();
        $request = Request::create('/api/items', 'GET');
        $request->attributes->set('_route', 'api_item_list');

        self::assertSame('/api/items', $resolver->resolve($request));
    }

    public function testRouterLookupIsCachedPerRouteName(): void
    {
        $collection = new RouteCollection();
        $collection->add('api_item_show', new Route('/api/items/{id}'));

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('getRouteCollection')->willReturn($collection);

        $resolver = new RouteTemplateResolver($router);

        $request = Request::create('/api/items/5', 'GET');
        $request->attributes->set('_route', 'api_item_show');

        self::assertSame('/api/items/{id}', $resolver->resolve($request));
        self::assertSame('/api/items/{id}', $resolver->resolve($request));
    }

    public function testRouterThrowingFallsBackToSynthesis(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willThrowException(new \RuntimeException('no collection'));

        $resolver = new RouteTemplateResolver($router);
        $request = Request::create('/api/items/5', 'GET');
        $request->attributes->set('_route', 'api_item_show');
        $request->attributes->set('_route_params', ['id' => '5']);

        self::assertSame('/api/items/{id}', $resolver->resolve($request));
    }
}
