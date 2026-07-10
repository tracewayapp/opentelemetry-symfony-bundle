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
    public function process(ContainerBuilder $container): void
    {
        $busId = $this->resolveDefaultBusId($container);
        if (null === $busId) {
            return;
        }

        $parameter = $busId.'.middleware';
        if (!$container->hasParameter($parameter)) {
            return;
        }

        $middleware = $container->getParameter($parameter);
        if (!\is_array($middleware)) {
            return;
        }

        if ($container->has(OpenTelemetryMiddleware::class)) {
            $middleware = $this->insertMiddleware($middleware, OpenTelemetryMiddleware::class);
        }

        if ($this->isMetricsEnabled($container) && $container->has(OpenTelemetryMetricsMiddleware::class)) {
            $middleware = $this->insertMiddleware($middleware, OpenTelemetryMetricsMiddleware::class);
        }

        $container->setParameter($parameter, $middleware);
    }

    private function resolveDefaultBusId(ContainerBuilder $container): ?string
    {
        if ($container->hasAlias('messenger.default_bus')) {
            return (string) $container->getAlias('messenger.default_bus');
        }

        $busIds = array_keys($container->findTaggedServiceIds('messenger.bus'));
        if (1 === \count($busIds)) {
            return $busIds[0];
        }

        if ($container->hasParameter('messenger.bus.default.middleware')) {
            return 'messenger.bus.default';
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $middleware
     *
     * @return array<int|string, mixed>
     */
    private function insertMiddleware(array $middleware, string $serviceId): array
    {
        if ($this->hasMiddleware($middleware, $serviceId)) {
            return $middleware;
        }

        $position = $this->findTerminalMiddlewarePosition($middleware);
        array_splice($middleware, $position, 0, [['id' => $serviceId]]);

        return $middleware;
    }

    /**
     * @param array<int|string, mixed> $middleware
     */
    private function hasMiddleware(array $middleware, string $serviceId): bool
    {
        foreach ($middleware as $item) {
            $id = $this->middlewareId($item);
            if ($serviceId === $id || 'messenger.middleware.'.$serviceId === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $middleware
     */
    private function findTerminalMiddlewarePosition(array $middleware): int
    {
        foreach ($middleware as $position => $item) {
            if (\in_array($this->middlewareId($item), [
                'send_message',
                'messenger.middleware.send_message',
                'handle_message',
                'messenger.middleware.handle_message',
            ], true)) {
                return (int) $position;
            }
        }

        return \count($middleware);
    }

    private function middlewareId(mixed $item): ?string
    {
        if (\is_string($item)) {
            return $item;
        }

        if (!\is_array($item) || !isset($item['id'])) {
            return null;
        }

        return \is_string($item['id']) ? $item['id'] : null;
    }

    private function isMetricsEnabled(ContainerBuilder $container): bool
    {
        if (!$container->hasParameter('open_telemetry.metrics.enabled')) {
            return false;
        }

        return true === $container->getParameter('open_telemetry.metrics.enabled');
    }
}
