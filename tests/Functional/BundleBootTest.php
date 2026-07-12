<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckRunner;
use Traceway\OpenTelemetryBundle\Command\DoctorCommand;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredMiddleware as DoctrineMeteredMiddleware;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetryMetricsSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistry;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistryInterface;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\TracingInterface;
use Traceway\OpenTelemetryBundle\XRay\XRayBootstrapper;

final class BundleBootTest extends TestCase
{
    private ?OpenTelemetryTestKernel $kernel = null;

    /** @var callable|null */
    private mixed $previousExceptionHandler = null;

    protected function setUp(): void
    {
        $this->previousExceptionHandler = set_exception_handler(null);
        restore_exception_handler();
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }

        // Restore exception handler to the state before the test
        set_exception_handler($this->previousExceptionHandler);
    }

    public function testDefaultConfigBootsSuccessfully(): void
    {
        $container = $this->boot();

        self::assertInstanceOf(Tracing::class, $container->get(TracingInterface::class));
    }

    public function testCoreServicesAreWired(): void
    {
        $container = $this->boot();

        self::assertInstanceOf(OpenTelemetrySubscriber::class, $container->get(OpenTelemetrySubscriber::class));
        self::assertInstanceOf(ConsoleSubscriber::class, $container->get(ConsoleSubscriber::class));
        self::assertInstanceOf(OpenTelemetryMiddleware::class, $container->get(OpenTelemetryMiddleware::class));
    }

    public function testDoctorCommandIsWired(): void
    {
        $container = $this->boot();

        self::assertInstanceOf(DoctorCommand::class, $container->get(DoctorCommand::class));
        self::assertInstanceOf(CheckRunner::class, $container->get(CheckRunner::class));
    }

    public function testDoctorParametersAreSet(): void
    {
        $container = $this->boot();

        // The 6 parameters added for the doctor command must be set so checks can read them.
        self::assertTrue($container->getParameter('open_telemetry.traces.enabled'));
        self::assertSame('w3c', $container->getParameter('open_telemetry.traces.propagator'));
        self::assertSame('default', $container->getParameter('open_telemetry.traces.id_generator'));
        self::assertTrue($container->getParameter('open_telemetry.traces.messenger.enabled'));
        self::assertFalse($container->getParameter('open_telemetry.metrics.enabled'));
        self::assertFalse($container->getParameter('open_telemetry.logs.export.enabled'));
    }

    public function testTracesDisabledRemovesSubscriber(): void
    {
        $container = $this->boot(['traces' => ['enabled' => false]]);

        self::assertFalse($container->has(OpenTelemetrySubscriber::class));
        self::assertInstanceOf(Tracing::class, $container->get(TracingInterface::class));
    }

    public function testConsoleDisabledRemovesSubscriber(): void
    {
        $container = $this->boot(['traces' => ['console' => ['enabled' => false]]]);

        self::assertFalse($container->has(ConsoleSubscriber::class));
    }

    public function testMessengerDisabledRemovesMiddleware(): void
    {
        $container = $this->boot(['traces' => ['messenger' => ['enabled' => false]]]);

        self::assertFalse($container->has(OpenTelemetryMiddleware::class));
    }

    public function testCustomTracerNameWired(): void
    {
        $this->boot(['traces' => ['tracer_name' => 'my-app']]);

        self::assertSame(
            'my-app',
            $this->kernel->getContainer()->getParameter('open_telemetry.tracer_name'),
        );
    }

    public function testAllFeaturesDisabledStillBoots(): void
    {
        $container = $this->boot([
            'traces' => [
                'enabled' => false,
                'console' => ['enabled' => false],
                'messenger' => ['enabled' => false],
                'http_client' => ['enabled' => false],
                'doctrine' => ['enabled' => false],
                'cache' => ['enabled' => false],
                'twig' => ['enabled' => false],
            ],
            'logs' => [
                'correlation' => ['enabled' => false],
                'export' => ['enabled' => false],
            ],
        ]);

        self::assertInstanceOf(Tracing::class, $container->get(TracingInterface::class));
    }

    public function testLogExportBootsWithMonologBundle(): void
    {
        $container = $this->boot(
            ['logs' => ['export' => ['enabled' => true]]],
            [new \Symfony\Bundle\MonologBundle\MonologBundle()],
        );

        self::assertInstanceOf(OtelLogHandler::class, $container->get(OtelLogHandler::class));
        self::assertInstanceOf(OtelLoggerFlushSubscriber::class, $container->get(OtelLoggerFlushSubscriber::class));
    }

    public function testLogExportFailsWithoutMonologBundle(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('symfony/monolog-bundle');

        $this->boot(['logs' => ['export' => ['enabled' => true]]]);
    }

    public function testLogExportCaptureCodeAttributesFlagFlowsToHandler(): void
    {
        $container = $this->boot(
            [
                'logs' => ['export' => [
                    'enabled' => true,
                    'capture_code_attributes' => true,
                ]],
            ],
            [new \Symfony\Bundle\MonologBundle\MonologBundle()],
        );

        $handler = $container->get(OtelLogHandler::class);
        self::assertInstanceOf(OtelLogHandler::class, $handler);

        $captureFlag = (new \ReflectionClass($handler))->getProperty('captureCodeAttributes');
        self::assertTrue($captureFlag->getValue($handler));
    }

    public function testLogExportUnprefixedAttributesFlagFlowsToHandler(): void
    {
        $container = $this->boot(
            [
                'logs' => ['export' => [
                    'enabled' => true,
                    'unprefixed_attributes' => true,
                ]],
            ],
            [new \Symfony\Bundle\MonologBundle\MonologBundle()],
        );

        $handler = $container->get(OtelLogHandler::class);
        self::assertInstanceOf(OtelLogHandler::class, $handler);

        $unprefixedFlag = (new \ReflectionClass($handler))->getProperty('unprefixedAttributes');
        self::assertTrue($unprefixedFlag->getValue($handler));
    }

    public function testHttpClientExcludedHostsParameter(): void
    {
        $this->boot(['traces' => ['http_client' => ['excluded_hosts' => ['collector.local']]]]);

        self::assertSame(
            ['collector.local'],
            $this->kernel->getContainer()->getParameter('open_telemetry.http_client_excluded_hosts'),
        );
    }

    public function testCacheEnabledByDefault(): void
    {
        $this->boot();

        self::assertTrue(
            $this->kernel->getContainer()->getParameter('open_telemetry.cache_enabled'),
        );
    }

    public function testCacheDisabledParameter(): void
    {
        $this->boot(['traces' => ['cache' => ['enabled' => false]]]);

        self::assertFalse(
            $this->kernel->getContainer()->getParameter('open_telemetry.cache_enabled'),
        );
    }

    public function testMetricsDisabledByDefault(): void
    {
        $container = $this->boot();

        self::assertFalse($container->has(MeterRegistry::class));
        self::assertFalse($container->has(MeterRegistryInterface::class));
        self::assertFalse($container->has(OpenTelemetryMetricsMiddleware::class));
    }

    public function testMetricsEnabledRegistersMeterRegistry(): void
    {
        $container = $this->boot(['metrics' => ['enabled' => true]]);

        self::assertInstanceOf(MeterRegistry::class, $container->get(MeterRegistry::class));
        self::assertInstanceOf(MeterRegistry::class, $container->get(MeterRegistryInterface::class));
        self::assertFalse($container->has(OpenTelemetryMetricsMiddleware::class));
    }

    public function testMessengerMetricsEnabledRegistersSubscriber(): void
    {
        $container = $this->boot([
            'metrics' => [
                'enabled' => true,
                'messenger' => ['enabled' => true],
            ],
        ]);

        self::assertInstanceOf(
            OpenTelemetryMetricsMiddleware::class,
            $container->get(OpenTelemetryMetricsMiddleware::class),
        );
    }

    public function testMessengerMiddlewareIsWiredWhenApplicationDefinesBusMiddleware(): void
    {
        $container = $this->boot([], [], [
            'messenger' => [
                'default_bus' => 'default',
                'buses' => [
                    'default' => [
                        'middleware' => [
                            NoopMessengerMiddleware::class,
                        ],
                    ],
                ],
            ],
        ]);

        $middlewareClasses = $this->messengerMiddlewareClasses($container->get('messenger.default_bus'));

        self::assertContains(NoopMessengerMiddleware::class, $middlewareClasses);
        self::assertContains(OpenTelemetryMiddleware::class, $middlewareClasses);
        self::assertContains(SendMessageMiddleware::class, $middlewareClasses);
    }

    public function testMessengerMetricsWithoutMetricsEnabledFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('metrics.messenger.enabled');

        $this->boot([
            'metrics' => [
                'messenger' => ['enabled' => true],
            ],
        ]);
    }

    public function testDoctrineMetricsEnabledRegistersMiddleware(): void
    {
        $container = $this->boot([
            'metrics' => [
                'enabled' => true,
                'doctrine' => ['enabled' => true],
            ],
        ]);

        self::assertTrue($container->has(DoctrineMeteredMiddleware::class));
    }

    public function testDoctrineMetricsDisabledByDefault(): void
    {
        $container = $this->boot(['metrics' => ['enabled' => true]]);

        self::assertFalse($container->has(DoctrineMeteredMiddleware::class));
    }

    public function testDoctrineMetricsWithoutMetricsEnabledFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('metrics.doctrine.enabled');

        $this->boot([
            'metrics' => [
                'doctrine' => ['enabled' => true],
            ],
        ]);
    }

    public function testHttpServerMetricsDisabledByDefault(): void
    {
        $container = $this->boot(['metrics' => ['enabled' => true]]);

        self::assertFalse($container->has(OpenTelemetryMetricsSubscriber::class));
    }

    public function testHttpServerMetricsEnabledRegistersSubscriber(): void
    {
        $container = $this->boot([
            'metrics' => [
                'enabled' => true,
                'http_server' => ['enabled' => true],
            ],
        ]);

        self::assertInstanceOf(
            OpenTelemetryMetricsSubscriber::class,
            $container->get(OpenTelemetryMetricsSubscriber::class),
        );
    }

    public function testHttpServerMetricsWithoutMetricsEnabledFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('metrics.http_server.enabled');

        $this->boot([
            'metrics' => [
                'http_server' => ['enabled' => true],
            ],
        ]);
    }

    public function testHttpClientMetricsDisabledByDefault(): void
    {
        $container = $this->boot(['metrics' => ['enabled' => true]]);

        self::assertFalse($container->getParameter('open_telemetry.http_client_metrics_enabled'));
    }

    public function testHttpClientMetricsEnabledSetsParameter(): void
    {
        $container = $this->boot([
            'metrics' => [
                'enabled' => true,
                'http_client' => ['enabled' => true, 'excluded_hosts' => ['cdn.example.com']],
            ],
        ]);

        self::assertTrue($container->getParameter('open_telemetry.http_client_metrics_enabled'));
        self::assertSame(['cdn.example.com'], $container->getParameter('open_telemetry.http_client_metrics_excluded_hosts'));
    }

    public function testHttpClientMetricsWithoutMetricsEnabledFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('metrics.http_client.enabled');

        $this->boot([
            'metrics' => [
                'http_client' => ['enabled' => true],
            ],
        ]);
    }

    public function testDefaultConfigDoesNotRegisterXRayBootstrapper(): void
    {
        $container = $this->boot();

        self::assertFalse($container->has(XRayBootstrapper::class));
    }

    public function testXRayPropagatorWithoutPackageThrowsLogicException(): void
    {
        if (class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class)) {
            $this->markTestSkipped('open-telemetry/contrib-aws is installed; cannot test the missing-package error path.');
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('open-telemetry/contrib-aws');

        $this->boot(['traces' => ['propagator' => 'xray']]);
    }

    public function testXRayIdGeneratorWithoutPackageThrowsLogicException(): void
    {
        if (class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class)) {
            $this->markTestSkipped('open-telemetry/contrib-aws is installed; cannot test the missing-package error path.');
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('open-telemetry/contrib-aws');

        $this->boot(['traces' => ['id_generator' => 'xray']]);
    }

    public function testXRayPropagatorRegistersBootstrapper(): void
    {
        if (!class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class)) {
            $this->markTestSkipped('open-telemetry/contrib-aws is required for this test.');
        }

        $container = $this->boot(['traces' => ['propagator' => 'xray']]);

        self::assertInstanceOf(XRayBootstrapper::class, $container->get(XRayBootstrapper::class));
    }

    public function testXRayW3cPlusPropagatorRegistersBootstrapper(): void
    {
        if (!class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class)) {
            $this->markTestSkipped('open-telemetry/contrib-aws is required for this test.');
        }

        $container = $this->boot(['traces' => ['propagator' => 'w3c+xray']]);

        self::assertInstanceOf(XRayBootstrapper::class, $container->get(XRayBootstrapper::class));
    }

    public function testXRayIdGeneratorRegistersBootstrapper(): void
    {
        if (!class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class)) {
            $this->markTestSkipped('open-telemetry/contrib-aws is required for this test.');
        }

        $container = $this->boot(['traces' => ['id_generator' => 'xray']]);

        self::assertInstanceOf(XRayBootstrapper::class, $container->get(XRayBootstrapper::class));
    }

    /**
     * BC coverage for the load() path: legacy flat `traces_enabled` should
     * still disable the subscriber via the deprecation migration layer.
     * Remove in v3.0 alongside the BC layer in Configuration::migrateLegacyKeys().
     *
     * #[Group('legacy')] + SYMFONY_DEPRECATIONS_HELPER=max[self]=0 in phpunit.dist.xml
     * lets the bridge tolerate the self-deprecation without per-test assertions.
     */
    #[Group('legacy')]
    public function testLegacyTracesDisabledRemovesSubscriber(): void
    {
        $container = $this->boot(['traces_enabled' => false]);

        self::assertFalse($container->has(OpenTelemetrySubscriber::class));
    }

    /**
     * BC coverage for the prepend() path: legacy flat `messenger_enabled`
     * should still skip the framework.messenger middleware injection.
     * Remove in v3.0 alongside the BC layer.
     */
    #[Group('legacy')]
    public function testLegacyMessengerDisabledRemovesMiddleware(): void
    {
        $container = $this->boot(['messenger_enabled' => false]);

        self::assertFalse($container->has(OpenTelemetryMiddleware::class));
    }

    /**
     * @param array<string, mixed>                                       $otelConfig
     * @param list<\Symfony\Component\HttpKernel\Bundle\BundleInterface> $extraBundles
     * @param array<string, mixed>                                       $frameworkConfig
     */
    private function boot(array $otelConfig = [], array $extraBundles = [], array $frameworkConfig = []): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        $this->kernel = new OpenTelemetryTestKernel($otelConfig, $extraBundles, $frameworkConfig);
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }

    /**
     * @return list<class-string>
     */
    private function messengerMiddlewareClasses(object $messageBus): array
    {
        $property = new \ReflectionProperty($messageBus, 'middlewareAggregate');
        $middleware = iterator_to_array($property->getValue($messageBus), false);

        return array_map(
            static fn (object $middleware): string => $middleware::class,
            $middleware,
        );
    }
}
