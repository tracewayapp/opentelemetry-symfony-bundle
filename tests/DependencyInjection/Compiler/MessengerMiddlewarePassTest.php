<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\MessengerMiddlewarePass;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;

final class MessengerMiddlewarePassTest extends TestCase
{
    public function testInsertsTracingMiddlewareBeforeTerminalMessengerMiddleware(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $container->setParameter('open_telemetry.metrics.enabled', false);
        $container->setDefinition(OpenTelemetryMiddleware::class, new Definition(OpenTelemetryMiddleware::class));
        $container->setParameter('default.middleware', [
            ['id' => 'add_default_stamps_middleware'],
            ['id' => 'doctrine_ping_connection'],
            ['id' => 'doctrine_close_connection'],
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        (new MessengerMiddlewarePass())->process($container);

        self::assertSame([
            'add_default_stamps_middleware',
            'doctrine_ping_connection',
            'doctrine_close_connection',
            OpenTelemetryMiddleware::class,
            'send_message',
            'handle_message',
        ], $this->middlewareIds($container, 'default.middleware'));
    }

    public function testUsesConfiguredDefaultBusAlias(): void
    {
        $container = $this->containerWithDefaultBus('command.bus');
        $container->setParameter('open_telemetry.metrics.enabled', false);
        $container->setDefinition(OpenTelemetryMiddleware::class, new Definition(OpenTelemetryMiddleware::class));
        $container->setParameter('command.bus.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);
        $container->setParameter('event.bus.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        (new MessengerMiddlewarePass())->process($container);

        self::assertSame([
            OpenTelemetryMiddleware::class,
            'send_message',
            'handle_message',
        ], $this->middlewareIds($container, 'command.bus.middleware'));
        self::assertSame([
            'send_message',
            'handle_message',
        ], $this->middlewareIds($container, 'event.bus.middleware'));
    }

    public function testInsertsMetricsMiddlewareWhenMetricsAreEnabled(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $container->setParameter('open_telemetry.metrics.enabled', true);
        $container->setDefinition(OpenTelemetryMiddleware::class, new Definition(OpenTelemetryMiddleware::class));
        $container->setDefinition(OpenTelemetryMetricsMiddleware::class, new Definition(OpenTelemetryMetricsMiddleware::class));
        $container->setParameter('default.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        (new MessengerMiddlewarePass())->process($container);

        self::assertSame([
            OpenTelemetryMiddleware::class,
            OpenTelemetryMetricsMiddleware::class,
            'send_message',
            'handle_message',
        ], $this->middlewareIds($container, 'default.middleware'));
    }

    public function testDoesNotInsertMetricsMiddlewareWhenMetricsAreDisabled(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $container->setParameter('open_telemetry.metrics.enabled', false);
        $container->setDefinition(OpenTelemetryMiddleware::class, new Definition(OpenTelemetryMiddleware::class));
        $container->setDefinition(OpenTelemetryMetricsMiddleware::class, new Definition(OpenTelemetryMetricsMiddleware::class));
        $container->setParameter('default.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        (new MessengerMiddlewarePass())->process($container);

        self::assertSame([
            OpenTelemetryMiddleware::class,
            'send_message',
            'handle_message',
        ], $this->middlewareIds($container, 'default.middleware'));
    }

    public function testDoesNotDuplicateManuallyConfiguredMiddleware(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $container->setParameter('open_telemetry.metrics.enabled', false);
        $container->setDefinition(OpenTelemetryMiddleware::class, new Definition(OpenTelemetryMiddleware::class));
        $container->setParameter('default.middleware', [
            ['id' => OpenTelemetryMiddleware::class],
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        (new MessengerMiddlewarePass())->process($container);

        self::assertSame([
            OpenTelemetryMiddleware::class,
            'send_message',
            'handle_message',
        ], $this->middlewareIds($container, 'default.middleware'));
    }

    public function testSkipsWhenMiddlewareServiceDoesNotExist(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $container->setParameter('open_telemetry.metrics.enabled', false);
        $container->setParameter('default.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        (new MessengerMiddlewarePass())->process($container);

        self::assertSame([
            'send_message',
            'handle_message',
        ], $this->middlewareIds($container, 'default.middleware'));
    }

    private function containerWithDefaultBus(string $busId): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setAlias('messenger.default_bus', $busId);

        return $container;
    }

    /**
     * @return list<string|null>
     */
    private function middlewareIds(ContainerBuilder $container, string $parameter): array
    {
        $middleware = $container->getParameter($parameter);
        \assert(\is_array($middleware));

        return array_map(
            static fn (mixed $item): ?string => \is_array($item) && \is_string($item['id'] ?? null) ? $item['id'] : null,
            array_values($middleware),
        );
    }
}
