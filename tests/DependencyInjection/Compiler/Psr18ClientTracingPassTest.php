<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection\Compiler;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\Psr18Client;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\Psr18ClientTracingPass;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\TracedPsr18Client;

final class Psr18ClientTracingPassTest extends TestCase
{
    public function testDecoratesPsr18ClientServices(): void
    {
        $container = $this->container(true);
        $container->setDefinition('app.psr18', new Definition(Psr18Client::class));
        $container->setDefinition('app.other', new Definition(\stdClass::class));

        (new Psr18ClientTracingPass())->process($container);

        self::assertTrue($container->hasDefinition('app.psr18.otel'));
        $decorator = $container->getDefinition('app.psr18.otel');
        self::assertSame(TracedPsr18Client::class, $decorator->getClass());
        self::assertSame(['app.psr18', 'app.psr18.otel.inner', -16], $decorator->getDecoratedService());
        self::assertSame('test-tracer', $decorator->getArgument('$tracerName'));
        self::assertSame(['collector.internal'], $decorator->getArgument('$excludedHosts'));
        self::assertTrue($decorator->hasTag('kernel.reset'));
        self::assertFalse($container->hasDefinition('app.other.otel'));
    }

    public function testSkipsGuzzleClientsAbstractAndSyntheticDefinitions(): void
    {
        $container = $this->container(true);
        $container->setDefinition('app.guzzle', new Definition(GuzzleClient::class));
        $container->setDefinition('app.abstract', (new Definition(Psr18Client::class))->setAbstract(true));
        $container->setDefinition('app.synthetic', (new Definition(Psr18Client::class))->setSynthetic(true));

        (new Psr18ClientTracingPass())->process($container);

        self::assertFalse($container->hasDefinition('app.guzzle.otel'));
        self::assertFalse($container->hasDefinition('app.abstract.otel'));
        self::assertFalse($container->hasDefinition('app.synthetic.otel'));
    }

    public function testToleratesServiceClassesThatCannotBeAutoloaded(): void
    {
        $container = $this->container(true);
        $container->setDefinition('app.broken', new Definition('App\\Missing\\ClassWithUninstalledParent'));
        $container->setDefinition('app.psr18', new Definition(Psr18Client::class));

        (new Psr18ClientTracingPass())->process($container);

        self::assertFalse($container->hasDefinition('app.broken.otel'));
        self::assertTrue($container->hasDefinition('app.psr18.otel'));
    }

    public function testResolvesParameterizedClassNames(): void
    {
        $container = $this->container(true);
        $container->setParameter('app.client_class', Psr18Client::class);
        $container->setDefinition('app.psr18', new Definition('%app.client_class%'));

        (new Psr18ClientTracingPass())->process($container);

        self::assertTrue($container->hasDefinition('app.psr18.otel'));
    }

    public function testSkipsWhenDisabledOrParameterMissing(): void
    {
        $container = $this->container(false);
        $container->setDefinition('app.psr18', new Definition(Psr18Client::class));
        (new Psr18ClientTracingPass())->process($container);
        self::assertFalse($container->hasDefinition('app.psr18.otel'));

        $bare = new ContainerBuilder();
        $bare->setDefinition('app.psr18', new Definition(Psr18Client::class));
        (new Psr18ClientTracingPass())->process($bare);
        self::assertFalse($bare->hasDefinition('app.psr18.otel'));
    }

    private function container(bool $enabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.http_client.psr18_enabled', $enabled);
        $container->setParameter('open_telemetry.tracer_name', 'test-tracer');
        $container->setParameter('open_telemetry.http_client_excluded_hosts', ['collector.internal']);

        return $container;
    }
}
