<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection\Compiler;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\GuzzleClientTracingPass;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\TracingMiddleware;

final class GuzzleClientTracingPassTest extends TestCase
{
    public function testRegistersMiddlewareAndHandlerStackServices(): void
    {
        $container = $this->container(true);

        (new GuzzleClientTracingPass())->process($container);

        $middleware = $container->getDefinition(GuzzleClientTracingPass::MIDDLEWARE_ID);
        self::assertSame(TracingMiddleware::class, $middleware->getClass());
        self::assertSame('test-tracer', $middleware->getArgument('$tracerName'));
        self::assertSame(['collector.internal'], $middleware->getArgument('$excludedHosts'));
        self::assertTrue($middleware->isPublic());

        $stack = $container->getDefinition(GuzzleClientTracingPass::HANDLER_STACK_ID);
        self::assertSame(HandlerStack::class, $stack->getClass());
        self::assertSame([HandlerStack::class, 'create'], $stack->getFactory());
        self::assertFalse($stack->isShared());
        self::assertEquals([['push', [new Reference(GuzzleClientTracingPass::MIDDLEWARE_ID), TracingMiddleware::NAME]]], $stack->getMethodCalls());
    }

    public function testClientWithoutHandlerGetsTheTracedStack(): void
    {
        $container = $this->container(true);
        $container->setDefinition('app.guzzle', new Definition(Client::class));
        $container->setDefinition('app.guzzle_with_base', new Definition(Client::class, [['base_uri' => 'https://api.example.com']]));

        (new GuzzleClientTracingPass())->process($container);

        self::assertEquals(['handler' => new Reference(GuzzleClientTracingPass::HANDLER_STACK_ID)], $container->getDefinition('app.guzzle')->getArgument(0));
        self::assertEquals(
            ['base_uri' => 'https://api.example.com', 'handler' => new Reference(GuzzleClientTracingPass::HANDLER_STACK_ID)],
            $container->getDefinition('app.guzzle_with_base')->getArgument(0),
        );
    }

    public function testClientWithOwnHandlerIsLeftAlone(): void
    {
        $container = $this->container(true);
        $container->setDefinition('app.stack', new Definition(HandlerStack::class));
        $container->setDefinition('app.guzzle', new Definition(Client::class, [['handler' => new Reference('app.stack')]]));

        (new GuzzleClientTracingPass())->process($container);

        self::assertEquals(['handler' => new Reference('app.stack')], $container->getDefinition('app.guzzle')->getArgument(0));
    }

    public function testBuiltContainerProducesATracedGuzzleClient(): void
    {
        $container = $this->container(true);
        $container->setDefinition('app.guzzle', (new Definition(Client::class))->setPublic(true));

        (new GuzzleClientTracingPass())->process($container);
        $container->compile();

        $client = $container->get('app.guzzle');
        self::assertInstanceOf(Client::class, $client);
        $config = (new \ReflectionProperty(Client::class, 'config'))->getValue($client);
        self::assertIsArray($config);
        $handler = $config['handler'] ?? null;
        self::assertInstanceOf(HandlerStack::class, $handler);
        self::assertTrue($handler->hasHandler());
        self::assertStringContainsString(TracingMiddleware::NAME, (string) $handler);
    }

    public function testSkipsWhenDisabled(): void
    {
        $container = $this->container(false);
        $container->setDefinition('app.guzzle', new Definition(Client::class));

        (new GuzzleClientTracingPass())->process($container);

        self::assertFalse($container->hasDefinition(GuzzleClientTracingPass::MIDDLEWARE_ID));
        self::assertSame([], $container->getDefinition('app.guzzle')->getArguments());
    }

    private function container(bool $enabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client.guzzle_enabled', $enabled);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');
        $container->setParameter('open_telemetry.http_client_excluded_hosts', ['collector.internal']);

        return $container;
    }
}
