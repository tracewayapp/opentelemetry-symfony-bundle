<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\E2E;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

final class E2EKernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new OpenTelemetryBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'e2e',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
            'cache' => ['app' => 'cache.adapter.filesystem'],
        ]);

        $container->extension('open_telemetry', [
            'traces' => ['doctrine' => ['max_spans_per_trace' => HelloController::MAX_DB_SPANS]],
        ]);

        $container->services()
            ->set('e2e.guzzle', \GuzzleHttp\Client::class)
            ->set(HelloController::class)
            ->autowire()
            ->arg('$guzzle', service('e2e.guzzle'))
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('hello', '/hello/{name}')->controller(HelloController::class);
    }
}
