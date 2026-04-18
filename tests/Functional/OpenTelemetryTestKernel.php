<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Functional;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

final class OpenTelemetryTestKernel extends Kernel
{
    /** @var array<string, mixed> */
    private array $otelConfig;

    /** @var list<\Symfony\Component\HttpKernel\Bundle\BundleInterface> */
    private array $extraBundles;

    /**
     * @param array<string, mixed> $otelConfig
     * @param list<\Symfony\Component\HttpKernel\Bundle\BundleInterface> $extraBundles
     */
    public function __construct(
        array $otelConfig = [],
        array $extraBundles = [],
    ) {
        $this->otelConfig = $otelConfig;
        $this->extraBundles = $extraBundles;

        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();

        foreach ($this->extraBundles as $bundle) {
            yield $bundle;
        }

        yield new OpenTelemetryBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $otelConfig = $this->otelConfig;

        $loader->load(function (ContainerBuilder $container) use ($otelConfig): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
            ]);

            if ([] !== $otelConfig) {
                $container->loadFromExtension('open_telemetry', $otelConfig);
            }
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Traceway\\OpenTelemetryBundle\\')) {
                        $definition->setPublic(true);
                    }
                }

                foreach ($container->getAliases() as $id => $alias) {
                    if (str_starts_with($id, 'Traceway\\OpenTelemetryBundle\\')) {
                        $alias->setPublic(true);
                    }
                }
            }
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/otel_bundle_tests/' . spl_object_id($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/otel_bundle_tests/logs';
    }
}
