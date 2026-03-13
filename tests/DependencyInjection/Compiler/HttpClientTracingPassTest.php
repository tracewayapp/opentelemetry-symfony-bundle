<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Traceway\OpenTelemetryBundle\HttpClient\TraceableHttpClient;

final class HttpClientTracingPassTest extends TestCase
{
    public function testDecoratesDefaultHttpClient(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client_enabled', true);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        $pass = new HttpClientTracingPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('http_client.otel'));

        $decorator = $container->getDefinition('http_client.otel');
        self::assertSame(TraceableHttpClient::class, $decorator->getClass());
        self::assertSame('test-tracer', $decorator->getArgument('$tracerName'));
    }

    public function testDecoratesTaggedScopedClients(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client_enabled', true);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');

        $scopedDef = new Definition(HttpClientInterface::class);
        $scopedDef->addTag('http_client.client');
        $container->setDefinition('my_api.client', $scopedDef);

        $pass = new HttpClientTracingPass();
        $pass->process($container);

        self::assertTrue($container->hasDefinition('my_api.client.otel'));
    }

    public function testSkipsWhenDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client_enabled', false);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        $pass = new HttpClientTracingPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition('http_client.otel'));
    }

    public function testSkipsWhenParameterMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('http_client', new Definition(HttpClientInterface::class));

        $pass = new HttpClientTracingPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition('http_client.otel'));
    }
}
