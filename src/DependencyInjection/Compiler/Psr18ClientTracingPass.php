<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\TracedPsr18Client;

/**
 * Decorates every service implementing PSR-18 ClientInterface with {@see TracedPsr18Client}.
 *
 * Guzzle clients are skipped: callers type-hint GuzzleHttp\ClientInterface, which the
 * decorator does not implement, and {@see GuzzleClientTracingPass} covers them through
 * the handler stack where every attempt is visible.
 */
final class Psr18ClientTracingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('open_telemetry.http_client.psr18_enabled')
            || true !== $container->getParameter('open_telemetry.http_client.psr18_enabled')) {
            return;
        }

        $tracerName = $container->getParameter('open_telemetry.tracer_name');
        \assert(\is_string($tracerName));

        /** @var string[] $excludedHosts */
        $excludedHosts = $container->hasParameter('open_telemetry.http_client_excluded_hosts')
            ? $container->getParameter('open_telemetry.http_client_excluded_hosts')
            : [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$this->isDecoratable($container, $definition)) {
                continue;
            }

            $decoratorId = $id.'.otel';
            $innerId = $decoratorId.'.inner';

            $decorator = new Definition(TracedPsr18Client::class);
            $decorator->setArgument('$client', new Reference($innerId));
            $decorator->setArgument('$tracerName', $tracerName);
            $decorator->setArgument('$excludedHosts', $excludedHosts);
            $decorator->setDecoratedService($id, $innerId, -16);
            $decorator->addTag('kernel.reset', ['method' => 'reset']);

            $container->setDefinition($decoratorId, $decorator);
        }
    }

    private function isDecoratable(ContainerBuilder $container, Definition $definition): bool
    {
        if ($definition->isAbstract() || $definition->isSynthetic() || null !== $definition->getDecoratedService()) {
            return false;
        }

        $class = $definition->getClass();
        if (null === $class) {
            return false;
        }

        $class = $container->getParameterBag()->resolveValue($class);
        if (!\is_string($class) || !self::implementsSafely($class, ClientInterface::class)) {
            return false;
        }

        if (is_a($class, TracedPsr18Client::class, true)) {
            return false;
        }

        return !interface_exists(\GuzzleHttp\ClientInterface::class) || !is_a($class, \GuzzleHttp\ClientInterface::class, true);
    }

    /**
     * Autoloading a service class can throw when one of its own dependencies is
     * not installed (an optional normalizer, a missing interface); such a class
     * cannot be a client we need to decorate.
     */
    private static function implementsSafely(string $class, string $interface): bool
    {
        try {
            return (class_exists($class) || interface_exists($class)) && is_a($class, $interface, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
