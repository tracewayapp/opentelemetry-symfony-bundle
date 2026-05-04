<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\CacheTracingPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientMetricsPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientTracingPass;

final class OpenTelemetryBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientTracingPass());
        $container->addCompilerPass(new HttpClientMetricsPass());
        $container->addCompilerPass(new CacheTracingPass());
    }
}
