<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle\HttpClientInstrumentationCheck;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\InstrumentedPsr18Client;
use Traceway\OpenTelemetryBundle\HttpClient\Psr\RequestMeter;

/**
 * Decorates PSR-18 client services with {@see InstrumentedPsr18Client}.
 *
 * Only a service whose class implements nothing beyond ClientInterface and
 * ResetInterface is decorated: the decorator replaces the service, so any other
 * interface (Symfony's Psr18Client is also a PSR-17 factory, Guzzle is
 * GuzzleHttp\ClientInterface, php-http adapters are HTTPlug clients) would
 * disappear from it and break consumers. Those multi-interface clients wrap a
 * transport that is traced already: Symfony HttpClient, or Guzzle through
 * {@see GuzzleClientTracingPass}.
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

        $meter = null;
        $instrumented = [];
        $excluded = self::excludedServices($container);
        foreach ($container->getDefinitions() as $id => $definition) {
            if (\in_array($id, $excluded, true) || !$this->isDecoratable($container, $definition)) {
                continue;
            }
            $meter ??= self::meterReference($container);

            $decoratorId = $id.'.otel';
            $innerId = $decoratorId.'.inner';

            $decorator = new Definition(InstrumentedPsr18Client::class);
            $decorator->setArgument('$client', new Reference($innerId));
            $decorator->setArgument('$tracerName', $tracerName);
            $decorator->setArgument('$excludedHosts', $excludedHosts);
            $decorator->setArgument('$meter', $meter);
            $decorator->setDecoratedService($id, $innerId, -16);
            $decorator->addTag('kernel.reset', ['method' => 'reset']);

            $container->setDefinition($decoratorId, $decorator);
            $instrumented[] = $id;
        }

        $container->setParameter(HttpClientInstrumentationCheck::PARAMETER_PREFIX.'psr18', $instrumented);
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
        if (!\is_string($class) || InstrumentedPsr18Client::class === ltrim($class, '\\')) {
            return false;
        }

        $interfaces = self::interfacesOf($class);
        if (null === $interfaces || !\in_array(ClientInterface::class, $interfaces, true)) {
            return false;
        }

        return [] === array_diff($interfaces, self::DECORATOR_INTERFACES);
    }

    private const DECORATOR_INTERFACES = [ClientInterface::class, ResetInterface::class];

    /**
     * Autoloading a service class can throw when one of its own dependencies is
     * not installed (an optional normalizer, a missing interface); such a class
     * cannot be a client we need to decorate.
     *
     * @return list<string>|null
     */
    private static function interfacesOf(string $class): ?array
    {
        try {
            if (interface_exists($class)) {
                return [$class, ...array_values(class_implements($class) ?: [])];
            }

            return class_exists($class) ? array_values(class_implements($class) ?: []) : null;
        } catch (\Throwable) {
            return null;
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
