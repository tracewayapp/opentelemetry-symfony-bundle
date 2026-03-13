<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Traceway\OpenTelemetryBundle\DependencyInjection\OpenTelemetryExtension;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\TracingInterface;

final class OpenTelemetryExtensionTest extends TestCase
{
    public function testDefaultServicesRegistered(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->hasDefinition(Tracing::class));
        self::assertTrue($container->hasDefinition(OpenTelemetrySubscriber::class));
        self::assertTrue($container->hasDefinition(OpenTelemetryMiddleware::class));
        self::assertTrue($container->hasAlias(TracingInterface::class));
    }

    public function testHttpClientParametersSet(): void
    {
        $container = $this->buildContainer([]);

        self::assertTrue($container->getParameter('open_telemetry.http_client_enabled'));
        self::assertSame('opentelemetry-symfony', $container->getParameter('open_telemetry.tracer_name'));
    }

    public function testHttpClientDisabled(): void
    {
        $container = $this->buildContainer(['http_client_enabled' => false]);

        self::assertFalse($container->getParameter('open_telemetry.http_client_enabled'));
    }

    public function testTracerNameWiredToAllServices(): void
    {
        $container = $this->buildContainer([
            'tracer_name' => 'custom-tracer',
        ]);

        $tracingDef = $container->getDefinition(Tracing::class);
        self::assertSame('custom-tracer', $tracingDef->getArgument('$tracerName'));

        $subscriberDef = $container->getDefinition(OpenTelemetrySubscriber::class);
        self::assertSame('custom-tracer', $subscriberDef->getArgument('$tracerName'));

        $middlewareDef = $container->getDefinition(OpenTelemetryMiddleware::class);
        self::assertSame('custom-tracer', $middlewareDef->getArgument('$tracerName'));
    }

    public function testSubscriberRemovedWhenTracesDisabled(): void
    {
        $container = $this->buildContainer(['traces_enabled' => false]);

        self::assertFalse($container->hasDefinition(OpenTelemetrySubscriber::class));
        self::assertTrue($container->hasDefinition(Tracing::class));
    }

    public function testMiddlewareRemovedWhenMessengerDisabled(): void
    {
        $container = $this->buildContainer(['messenger_enabled' => false]);

        self::assertFalse($container->hasDefinition(OpenTelemetryMiddleware::class));
        self::assertTrue($container->hasDefinition(OpenTelemetrySubscriber::class));
    }

    public function testSubscriberReceivesConfig(): void
    {
        $container = $this->buildContainer([
            'excluded_paths' => ['/health'],
            'record_client_ip' => false,
            'error_status_threshold' => 400,
        ]);

        $def = $container->getDefinition(OpenTelemetrySubscriber::class);

        self::assertSame(['/health'], $def->getArgument('$excludedPaths'));
        self::assertFalse($def->getArgument('$recordClientIp'));
        self::assertSame(400, $def->getArgument('$errorStatusThreshold'));
    }

    public function testMiddlewareReceivesRootSpansConfig(): void
    {
        $container = $this->buildContainer(['messenger_root_spans' => true]);

        $def = $container->getDefinition(OpenTelemetryMiddleware::class);
        self::assertTrue($def->getArgument('$rootSpans'));
    }

    public function testMiddlewareRootSpansDefaultFalse(): void
    {
        $container = $this->buildContainer([]);

        $def = $container->getDefinition(OpenTelemetryMiddleware::class);
        self::assertFalse($def->getArgument('$rootSpans'));
    }

    private function buildContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new OpenTelemetryExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
