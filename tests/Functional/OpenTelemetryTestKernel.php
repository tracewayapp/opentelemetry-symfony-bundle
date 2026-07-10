<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Functional;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

final class OpenTelemetryTestKernel extends Kernel
{
    /**
     * Monotonically increasing per-process counter used to give every kernel
     * instance a unique cache directory. spl_object_id() is unsuitable here
     * because PHP recycles object IDs after GC — under PHPUnit 13's faster
     * test-lifecycle teardown, two consecutive test kernels could share an ID
     * and the second would load the first's compiled container from disk,
     * silently masking the new test's config.
     *
     * The cache path also includes getmypid() so concurrent PHP processes
     * (paratest, accidental parallel invocations) don't collide on a shared
     * counter value of 1, 2, etc.
     */
    private static int $instanceCounter = 0;

    private readonly int $instanceId;

    /** @var array<string, mixed> */
    private array $otelConfig;

    /** @var list<\Symfony\Component\HttpKernel\Bundle\BundleInterface> */
    private array $extraBundles;

    /** @var array<string, mixed> */
    private array $frameworkConfig;

    /**
     * @param array<string, mixed>                                       $otelConfig
     * @param list<\Symfony\Component\HttpKernel\Bundle\BundleInterface> $extraBundles
     * @param array<string, mixed>                                       $frameworkConfig
     */
    public function __construct(
        array $otelConfig = [],
        array $extraBundles = [],
        array $frameworkConfig = [],
    ) {
        $this->instanceId = ++self::$instanceCounter;
        $this->otelConfig = $otelConfig;
        $this->extraBundles = $extraBundles;
        $this->frameworkConfig = $frameworkConfig;

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
        $frameworkConfig = $this->frameworkConfig;

        $loader->load(static function (ContainerBuilder $container) use ($otelConfig, $frameworkConfig): void {
            $container->loadFromExtension('framework', array_replace_recursive([
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
            ], $frameworkConfig));

            if (class_exists(NoopMessengerMiddleware::class)) {
                $container->setDefinition(NoopMessengerMiddleware::class, new Definition(NoopMessengerMiddleware::class));
            }

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
        return sys_get_temp_dir().'/otel_bundle_tests/'.getmypid().'_'.$this->instanceId;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/otel_bundle_tests/logs';
    }
}
