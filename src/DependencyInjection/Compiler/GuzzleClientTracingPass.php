<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle\HttpClientInstrumentationCheck;
use Traceway\OpenTelemetryBundle\HttpClient\Guzzle\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestMeter;

/**
 * Registers the Guzzle {@see OpenTelemetryMiddleware} service and gives every
 * GuzzleHttp\Client service that does not configure its own handler a
 * handler stack with the middleware pushed.
 *
 * A client with an explicit `handler` is left alone: push the middleware
 * service onto that stack yourself.
 */
final class GuzzleClientTracingPass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = OpenTelemetryMiddleware::class;
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

        $middleware = new Definition(OpenTelemetryMiddleware::class);
        $middleware->setArgument('$tracerName', $tracerName);
        $middleware->setArgument('$excludedHosts', $excludedHosts);
        $middleware->setArgument('$meter', self::meterReference($container));
        $middleware->setPublic(true);
        $middleware->addTag('kernel.reset', ['method' => 'reset']);
        $container->setDefinition(self::MIDDLEWARE_ID, $middleware);

        $stack = new Definition(\GuzzleHttp\HandlerStack::class);
        $stack->setFactory([\GuzzleHttp\HandlerStack::class, 'create']);
        $stack->addMethodCall('push', [new Reference(self::MIDDLEWARE_ID), OpenTelemetryMiddleware::NAME]);
        $stack->setShared(false);
        $container->setDefinition(self::HANDLER_STACK_ID, $stack);

        $instrumented = [];
        $excluded = self::excludedServices($container);
        foreach ($container->getDefinitions() as $id => $definition) {
            if (\in_array($id, $excluded, true) || !$this->isGuzzleClient($container, $definition)) {
                continue;
            }

            $arguments = $definition->getArguments();
            $config = $arguments[0] ?? $arguments['$config'] ?? [];
            if (!\is_array($config) || isset($config['handler'])) {
                continue;
            }

            $config['handler'] = new Reference(self::HANDLER_STACK_ID);
            $definition->setArgument(\array_key_exists('$config', $arguments) ? '$config' : 0, $config);
            $instrumented[] = $id;
        }

        $container->setParameter(HttpClientInstrumentationCheck::PARAMETER_PREFIX.'guzzle', $instrumented);
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

    private static function meterReference(ContainerBuilder $container): ?Reference
    {
        if (true !== ($container->hasParameter('open_telemetry.http_client_metrics_enabled') ? $container->getParameter('open_telemetry.http_client_metrics_enabled') : false)) {
            return null;
        }

        if (!$container->hasDefinition(RequestMeter::class)) {
            $meter = new Definition(RequestMeter::class);
            $meter->setArgument('$meterName', $container->hasParameter('open_telemetry.metrics_meter_name') ? $container->getParameter('open_telemetry.metrics_meter_name') : 'opentelemetry-symfony');
            $meter->setArgument('$excludedHosts', $container->hasParameter('open_telemetry.http_client_metrics_excluded_hosts') ? $container->getParameter('open_telemetry.http_client_metrics_excluded_hosts') : []);
            $meter->addTag('kernel.reset', ['method' => 'reset']);
            $container->setDefinition(RequestMeter::class, $meter);
        }

        return new Reference(RequestMeter::class);
    }

    /** @return list<string> */
    private static function excludedServices(ContainerBuilder $container): array
    {
        if (!$container->hasParameter('open_telemetry.http_client.excluded_services')) {
            return [];
        }

        $ids = $container->getParameter('open_telemetry.http_client.excluded_services');

        return \is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
