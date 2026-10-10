<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection\Compiler;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\GuzzleClientTracingPass;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestMeter;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class GuzzleClientTracingPassTest extends TestCase
{
    use OTelTestTrait;

    public function testRegistersMiddlewareAndHandlerStackServices(): void
    {
        $container = $this->container(true);

        (new GuzzleClientTracingPass())->process($container);

        $middleware = $container->getDefinition(GuzzleClientTracingPass::MIDDLEWARE_ID);
        self::assertSame(OpenTelemetryMiddleware::class, $middleware->getClass());
        self::assertSame('test-tracer', $middleware->getArgument('$tracerName'));
        self::assertSame(['collector.internal'], $middleware->getArgument('$excludedHosts'));
        self::assertTrue($middleware->isPublic());

        $stack = $container->getDefinition(GuzzleClientTracingPass::HANDLER_STACK_ID);
        self::assertSame(HandlerStack::class, $stack->getClass());
        self::assertSame([HandlerStack::class, 'create'], $stack->getFactory());
        self::assertFalse($stack->isShared());
        self::assertEquals([['push', [new Reference(GuzzleClientTracingPass::MIDDLEWARE_ID), OpenTelemetryMiddleware::NAME]]], $stack->getMethodCalls());
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
        self::assertSame(['app.guzzle', 'app.guzzle_with_base'], $container->getParameter('open_telemetry.http_client.instrumented.guzzle'));
    }

    public function testExcludedServiceKeepsItsOwnHandler(): void
    {
        $container = $this->container(true);
        $container->setParameter('open_telemetry.http_client.excluded_services', ['app.legacy_guzzle']);
        $container->setDefinition('app.legacy_guzzle', new Definition(Client::class));
        $container->setDefinition('app.guzzle', new Definition(Client::class));

        (new GuzzleClientTracingPass())->process($container);

        self::assertSame([], $container->getDefinition('app.legacy_guzzle')->getArguments());
        self::assertEquals(['handler' => new Reference(GuzzleClientTracingPass::HANDLER_STACK_ID)], $container->getDefinition('app.guzzle')->getArgument(0));
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

        $this->setUpOTel();
        try {
            $handler->setHandler(new MockHandler([new Response(200)]));
            $client->get('https://api.example.com/');

            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame('https://api.example.com/', $spans[0]->getAttributes()->get('url.full'));
        } finally {
            $this->tearDownOTel();
        }
    }

    public function testMeterIsWiredOnlyWhenHttpClientMetricsAreOn(): void
    {
        $container = $this->container(true);
        (new GuzzleClientTracingPass())->process($container);
        self::assertNull($container->getDefinition(GuzzleClientTracingPass::MIDDLEWARE_ID)->getArgument('$meter'));
        self::assertFalse($container->hasDefinition(RequestMeter::class));

        $container = $this->container(true);
        $container->setParameter('open_telemetry.http_client_metrics_enabled', true);
        $container->setParameter('open_telemetry.metrics_meter_name', 'meter');
        $container->setParameter('open_telemetry.http_client_metrics_excluded_hosts', ['metrics.internal']);
        (new GuzzleClientTracingPass())->process($container);

        self::assertEquals(new Reference(RequestMeter::class), $container->getDefinition(GuzzleClientTracingPass::MIDDLEWARE_ID)->getArgument('$meter'));
        $meter = $container->getDefinition(RequestMeter::class);
        self::assertSame('meter', $meter->getArgument('$meterName'));
        self::assertSame(['metrics.internal'], $meter->getArgument('$excludedHosts'));
        self::assertTrue($meter->hasTag('kernel.reset'));
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
