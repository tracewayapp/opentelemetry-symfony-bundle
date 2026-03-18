<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Traceway\OpenTelemetryBundle\DependencyInjection\OpenTelemetryExtension;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware as DoctrineTraceableMiddleware;
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

    public function testPrependRegistersMessengerMiddleware(): void
    {
        $container = new ContainerBuilder();
        $extension = new OpenTelemetryExtension();
        $extension->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        self::assertNotEmpty($frameworkConfigs);

        $messengerConfig = $frameworkConfigs[0]['messenger'] ?? null;
        self::assertNotNull($messengerConfig);

        $middleware = $messengerConfig['buses']['messenger.bus.default']['middleware'] ?? [];
        self::assertContains(OpenTelemetryMiddleware::class, $middleware);
    }

    public function testPrependSkippedWhenMessengerDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('open_telemetry', ['messenger_enabled' => false]);

        $extension = new OpenTelemetryExtension();
        $extension->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        self::assertEmpty($frameworkConfigs);
    }

    public function testDoctrineMiddlewareRegisteredWhenEnabled(): void
    {
        $container = $this->buildContainer(['doctrine_enabled' => true]);

        self::assertTrue($container->hasDefinition(DoctrineTraceableMiddleware::class));

        $def = $container->getDefinition(DoctrineTraceableMiddleware::class);
        self::assertTrue($def->hasTag('doctrine.middleware'));
        self::assertTrue($def->getArgument('$recordStatements'));
    }

    public function testDoctrineMiddlewareNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(['doctrine_enabled' => false]);

        self::assertFalse($container->hasDefinition(DoctrineTraceableMiddleware::class));
    }

    public function testDoctrineRecordStatementsConfigured(): void
    {
        $container = $this->buildContainer([
            'doctrine_enabled' => true,
            'doctrine_record_statements' => false,
        ]);

        $def = $container->getDefinition(DoctrineTraceableMiddleware::class);
        self::assertFalse($def->getArgument('$recordStatements'));
    }

    public function testDoctrineTracerNameWired(): void
    {
        $container = $this->buildContainer([
            'tracer_name' => 'my-tracer',
            'doctrine_enabled' => true,
        ]);

        $def = $container->getDefinition(DoctrineTraceableMiddleware::class);
        self::assertSame('my-tracer', $def->getArgument('$tracerName'));
    }

    private function buildContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new OpenTelemetryExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
