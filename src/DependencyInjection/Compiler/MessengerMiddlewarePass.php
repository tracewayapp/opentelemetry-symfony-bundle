<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;

/**
 * Adds Traceway middleware to the normalized Messenger middleware parameter.
 *
 * FrameworkBundle creates the "<bus id>.middleware" parameter before compiler
 * passes run, and MessengerPass consumes it later in the same phase. Mutating
 * the parameter here avoids framework config list-merge semantics.
 */
final class MessengerMiddlewarePass implements CompilerPassInterface
{
    /** Service id => the pre-4.1 class-name id an application may still list in its bus config. */
    private const OPENTELEMETRY_MIDDLEWARE_IDS = [
        'open_telemetry.messenger.middleware' => OpenTelemetryMiddleware::class,
        'open_telemetry.messenger.metrics_middleware' => OpenTelemetryMetricsMiddleware::class,
    ];

    private const TERMINAL_MIDDLEWARE_IDS = [
        'send_message',
        'messenger.middleware.send_message',
        'handle_message',
        'messenger.middleware.handle_message',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias('messenger.default_bus')) {
            return;
        }

        $parameter = $container->getAlias('messenger.default_bus').'.middleware';
        if (!$container->hasParameter($parameter)) {
            return;
        }

        /** @var list<array{id: string, arguments?: array<int|string, mixed>}> $middlewares */
        $middlewares = $container->getParameter($parameter);
        $middlewareIds = array_column($middlewares, 'id');
        $middlewareToInsert = [];

        foreach (self::OPENTELEMETRY_MIDDLEWARE_IDS as $serviceId => $classId) {
            if (!$container->has($serviceId)) {
                continue;
            }

            // Listed by hand under either id: inserting it again would trace every message twice.
            foreach ([$serviceId, $classId] as $id) {
                if (\in_array($id, $middlewareIds, true) || \in_array('messenger.middleware.'.$id, $middlewareIds, true)) {
                    continue 2;
                }
            }

            $middlewareToInsert[] = ['id' => $serviceId];
        }

        if ([] === $middlewareToInsert) {
            return;
        }

        $position = \count($middlewares);
        foreach ($middlewareIds as $key => $id) {
            if (\in_array($id, self::TERMINAL_MIDDLEWARE_IDS, true)) {
                $position = $key;
                break;
            }
        }

        $newMiddlewares = [
            ...\array_slice($middlewares, 0, $position),
            ...$middlewareToInsert,
            ...\array_slice($middlewares, $position),
        ];

        $container->setParameter($parameter, $newMiddlewares);
    }
}
