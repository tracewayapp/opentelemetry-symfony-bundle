<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Soak;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

/**
 * Every instrumentation on, the way a long-running worker serves requests.
 */
final class SoakKernel extends Kernel
{
    public const PUBLIC_IDS = ['http_client', 'psr18.http_client', 'cache.app', 'logger', 'messenger.default_bus', 'services_resetter', 'app.guzzle', 'app.psr18'];

    public function __construct(private readonly string $dir)
    {
        parent::__construct('soak', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new MonologBundle();
        yield new OpenTelemetryBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'soak',
                'test' => true,
                'http_method_override' => false,
                'http_client' => ['mock_response_factory' => 'app.mock_responses'],
                'cache' => ['app' => 'cache.adapter.array'],
                'messenger' => [
                    'transports' => ['sync' => 'sync://'],
                    'routing' => [SoakMessage::class => 'sync'],
                ],
            ]);
            $container->loadFromExtension('monolog', ['handlers' => []]);
            $container->loadFromExtension('open_telemetry', [
                'traces' => [
                    'doctrine' => ['enabled' => true, 'record_statements' => true],
                    'twig' => ['enabled' => true],
                    'cache' => ['enabled' => true],
                ],
                'metrics' => [
                    'enabled' => true,
                    'flush' => ['enabled' => false],
                    'messenger' => ['enabled' => true],
                    'doctrine' => ['enabled' => true],
                    'http_server' => ['enabled' => true],
                    'http_client' => ['enabled' => true],
                ],
                'logs' => ['export' => ['enabled' => true, 'level' => 'error']],
            ]);

            $container->setDefinition('app.mock_responses', new Definition(SoakMockResponses::class));
            $container->setDefinition(SoakMessageHandler::class, (new Definition(SoakMessageHandler::class))->addTag('messenger.message_handler'));
            $container->setDefinition('app.guzzle', new Definition(\GuzzleHttp\Client::class));
            $container->setDefinition('app.psr18', new Definition(SoakPsr18Client::class));
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (\in_array($id, SoakKernel::PUBLIC_IDS, true) || str_starts_with($id, 'Traceway\\')) {
                        $definition->setPublic(true);
                    }
                }
                foreach ($container->getAliases() as $id => $alias) {
                    if (\in_array($id, SoakKernel::PUBLIC_IDS, true) || str_starts_with($id, 'Traceway\\')) {
                        $alias->setPublic(true);
                    }
                }
            }
        });
    }

    public function getCacheDir(): string
    {
        return $this->dir.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->dir.'/log';
    }

    public function getProjectDir(): string
    {
        return $this->dir;
    }
}
