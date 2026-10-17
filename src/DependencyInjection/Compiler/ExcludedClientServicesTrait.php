<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @internal traces.http_client.excluded_services, shared by every HTTP client pass. */
trait ExcludedClientServicesTrait
{
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
