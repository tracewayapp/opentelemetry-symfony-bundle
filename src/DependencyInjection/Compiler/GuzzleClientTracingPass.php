<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\TracingMiddleware;

/**
 * Registers the Guzzle {@see TracingMiddleware} service and gives every
 * GuzzleHttp\Client service that does not configure its own handler a
 * handler stack with the middleware pushed.
 *
 * A client with an explicit `handler` is left alone: push the middleware
 * service onto that stack yourself.
 */
final class GuzzleClientTracingPass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = TracingMiddleware::class;
    public const HANDLER_STACK_ID = 'open_telemetry.guzzle.handler_stack';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('open_telemetry.http_client.guzzle_enabled')
            || true !== $container->getParameter('open_telemetry.http_client.guzzle_enabled')
            || !class_exists(\GuzzleHttp\Client::class)) {
            return;
        }

        $tracerName = $container->getParameter('open_telemetry.tracer_name');
        \assert(\is_string($tracerName));

        /** @var string[] $excludedHosts */
        $excludedHosts = $container->hasParameter('open_telemetry.http_client_excluded_hosts')
            ? $container->getParameter('open_telemetry.http_client_excluded_hosts')
            : [];

        $middleware = new Definition(TracingMiddleware::class);
        $middleware->setArgument('$tracerName', $tracerName);
        $middleware->setArgument('$excludedHosts', $excludedHosts);
        $middleware->setPublic(true);
        $middleware->addTag('kernel.reset', ['method' => 'reset']);
        $container->setDefinition(self::MIDDLEWARE_ID, $middleware);

        $stack = new Definition(\GuzzleHttp\HandlerStack::class);
        $stack->setFactory([\GuzzleHttp\HandlerStack::class, 'create']);
        $stack->addMethodCall('push', [new Reference(self::MIDDLEWARE_ID), TracingMiddleware::NAME]);
        $stack->setShared(false);
        $container->setDefinition(self::HANDLER_STACK_ID, $stack);

        foreach ($container->getDefinitions() as $definition) {
            if (!$this->isGuzzleClient($container, $definition)) {
                continue;
            }

            $arguments = $definition->getArguments();
            $config = $arguments[0] ?? $arguments['$config'] ?? [];
            if (!\is_array($config) || isset($config['handler'])) {
                continue;
            }

            $config['handler'] = new Reference(self::HANDLER_STACK_ID);
            $definition->setArgument(\array_key_exists('$config', $arguments) ? '$config' : 0, $config);
        }
    }

    private function isGuzzleClient(ContainerBuilder $container, Definition $definition): bool
    {
        if ($definition->isAbstract() || $definition->isSynthetic() || null !== $definition->getFactory()) {
            return false;
        }

        $class = $definition->getClass();
        if (null === $class) {
            return false;
        }

        $class = $container->getParameterBag()->resolveValue($class);
        if (!\is_string($class)) {
            return false;
        }

        try {
            return class_exists($class) && is_a($class, \GuzzleHttp\Client::class, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
