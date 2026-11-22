<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;

/**
 * Adds Traceway middleware to the normalized Messenger middleware parameter of the default bus,
 * and of every bus when the matching all_buses option is on.
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

    /** Service id => parameter holding its all_buses option. */
    private const ALL_BUSES_PARAMETERS = [
        'open_telemetry.messenger.middleware' => 'open_telemetry.traces.messenger.all_buses',
        'open_telemetry.messenger.metrics_middleware' => 'open_telemetry.metrics.messenger.all_buses',
    ];

    private const TERMINAL_MIDDLEWARE_IDS = [
        'send_message',
        'messenger.middleware.send_message',
        'handle_message',
        'messenger.middleware.handle_message',
    ];

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, list<string>> $middlewareByBus */
        $middlewareByBus = [];

        foreach (self::ALL_BUSES_PARAMETERS as $serviceId => $allBusesParameter) {
            if (!$container->has($serviceId)) {
                continue;
            }

            foreach ($this->targetBusIds($container, $allBusesParameter) as $busId) {
                $middlewareByBus[$busId][] = $serviceId;
            }
        }

        foreach ($middlewareByBus as $busId => $serviceIds) {
            $this->insert($container, $busId.'.middleware', $serviceIds);
        }
    }

    /**
     * @return list<string>
     */
    private function targetBusIds(ContainerBuilder $container, string $allBusesParameter): array
    {
        $busIds = [];

        if ($container->hasAlias('messenger.default_bus')) {
            $busIds[] = (string) $container->getAlias('messenger.default_bus');
        }

        if ($container->hasParameter($allBusesParameter) && true === $container->getParameter($allBusesParameter)) {
            foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
                $busIds[] = $busId;
            }
        }

        return array_values(array_unique($busIds));
    }

    /**
     * @param list<string> $serviceIds
     */
    private function insert(ContainerBuilder $container, string $parameter, array $serviceIds): void
    {
        if (!$container->hasParameter($parameter)) {
            return;
        }

        /** @var list<array{id: string, arguments?: array<int|string, mixed>}> $middlewares */
        $middlewares = $container->getParameter($parameter);
        $middlewareIds = array_column($middlewares, 'id');
        $middlewareToInsert = [];

        foreach ($serviceIds as $serviceId) {
            // Listed by hand under either id: inserting it again would trace every message twice.
            foreach ([$serviceId, self::OPENTELEMETRY_MIDDLEWARE_IDS[$serviceId]] as $id) {
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
