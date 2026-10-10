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
    private const TRACING_ID = 'open_telemetry.messenger.middleware';
    private const METRICS_ID = 'open_telemetry.messenger.metrics_middleware';

    public function testDoesNotDuplicateMiddlewareListedUnderTheNewIds(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $this->registerMiddleware($container);
        $container->setParameter('default.middleware', [
            ['id' => self::TRACING_ID],
            ['id' => 'messenger.middleware.'.self::METRICS_ID],
            ['id' => 'send_message'],
        ]);

        (new MessengerMiddlewarePass())->process($container);

        self::assertSame([self::TRACING_ID, 'messenger.middleware.'.self::METRICS_ID, 'send_message'], $this->middlewareIds($container, 'default.middleware'));
    }

    /** As the bundle registers them: bundle-alias ids, with the pre-4.1 class-name ids as aliases. */
    private function registerMiddleware(ContainerBuilder $container, bool $metrics = true): void
    {
        $container->setDefinition(self::TRACING_ID, new Definition(OpenTelemetryMiddleware::class));
        $container->setAlias(OpenTelemetryMiddleware::class, self::TRACING_ID);
        if ($metrics) {
            $container->setDefinition(self::METRICS_ID, new Definition(OpenTelemetryMetricsMiddleware::class));
            $container->setAlias(OpenTelemetryMetricsMiddleware::class, self::METRICS_ID);
        }
    }

    public function testInsertsAvailableMiddlewareOnConfiguredDefaultBus(): void
    {
        $container = $this->containerWithDefaultBus('command.bus');
        $this->registerMiddleware($container);
        $container->setParameter('command.bus.middleware', [
            ['id' => 'add_default_stamps_middleware'],
            ['id' => 'application_middleware'],
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);
        $container->setParameter('event.bus.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        self::assertSame(
            [
                'add_default_stamps_middleware',
                'application_middleware',
                self::TRACING_ID,
                self::METRICS_ID,
                'send_message',
                'handle_message',
            ],
            $this->middlewareIds($container, 'command.bus.middleware'),
        );
        self::assertSame(
            ['send_message', 'handle_message'],
            $this->middlewareIds($container, 'event.bus.middleware'),
        );
    }

    public function testDoesNotDuplicateManuallyConfiguredMiddleware(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $this->registerMiddleware($container);
        $container->setParameter('default.middleware', [
            ['id' => OpenTelemetryMiddleware::class],
            ['id' => 'messenger.middleware.'.OpenTelemetryMetricsMiddleware::class],
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        self::assertSame(
            [
                OpenTelemetryMiddleware::class,
                'messenger.middleware.'.OpenTelemetryMetricsMiddleware::class,
                'send_message',
                'handle_message',
            ],
            $this->middlewareIds($container, 'default.middleware'),
        );
    }

    public function testAppendsMiddlewareWhenDefaultMiddlewareIsDisabled(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $this->registerMiddleware($container, metrics: false);
        $container->setParameter('default.middleware', [
            ['id' => 'application_middleware'],
        ]);

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        self::assertSame(
            ['application_middleware', self::TRACING_ID],
            $this->middlewareIds($container, 'default.middleware'),
        );
    }

    public function testSkipsWhenMiddlewareServicesDoNotExist(): void
    {
        $container = $this->containerWithDefaultBus('default');
        $container->setParameter('default.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        $pass = new MessengerMiddlewarePass();
        $pass->process($container);

        self::assertSame(
            ['send_message', 'handle_message'],
            $this->middlewareIds($container, 'default.middleware'),
        );
    }

    public function testSkipsWithoutDefaultBusAliasOrMiddlewareParameter(): void
    {
        $containerWithoutAlias = new ContainerBuilder();
        $containerWithoutAlias->setDefinition(OpenTelemetryMiddleware::class, new Definition(OpenTelemetryMiddleware::class));
        $containerWithoutAlias->setParameter('default.middleware', [
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);

        $pass = new MessengerMiddlewarePass();
        $pass->process($containerWithoutAlias);

        self::assertSame(
            ['send_message', 'handle_message'],
            $this->middlewareIds($containerWithoutAlias, 'default.middleware'),
        );

        $containerWithoutParameter = $this->containerWithDefaultBus('default');
        $containerWithoutParameter->setDefinition(OpenTelemetryMiddleware::class, new Definition(OpenTelemetryMiddleware::class));

        $pass->process($containerWithoutParameter);

        self::assertFalse($containerWithoutParameter->hasParameter('default.middleware'));
    }

    private function containerWithDefaultBus(string $busId): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setAlias('messenger.default_bus', $busId);

        return $container;
    }

    /**
     * @return list<string>
     */
    private function middlewareIds(ContainerBuilder $container, string $parameter): array
    {
        /** @var list<array{id: string}> $middleware */
        $middleware = $container->getParameter($parameter);

        return array_column($middleware, 'id');
    }
}
