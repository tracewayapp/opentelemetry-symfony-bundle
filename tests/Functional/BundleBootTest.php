<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\TracingInterface;

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

    public function testTracesDisabledRemovesSubscriber(): void
    {
        $container = $this->boot(['traces_enabled' => false]);

        self::assertFalse($container->has(OpenTelemetrySubscriber::class));
        self::assertInstanceOf(Tracing::class, $container->get(TracingInterface::class));
    }

    public function testConsoleDisabledRemovesSubscriber(): void
    {
        $container = $this->boot(['console_enabled' => false]);

        self::assertFalse($container->has(ConsoleSubscriber::class));
    }

    public function testMessengerDisabledRemovesMiddleware(): void
    {
        $container = $this->boot(['messenger_enabled' => false]);

        self::assertFalse($container->has(OpenTelemetryMiddleware::class));
    }

    public function testCustomTracerNameWired(): void
    {
        $this->boot(['tracer_name' => 'my-app']);

        self::assertSame(
            'my-app',
            $this->kernel->getContainer()->getParameter('open_telemetry.tracer_name'),
        );
    }

    public function testAllFeaturesDisabledStillBoots(): void
    {
        $container = $this->boot([
            'traces_enabled' => false,
            'console_enabled' => false,
            'messenger_enabled' => false,
            'http_client_enabled' => false,
            'doctrine_enabled' => false,
            'cache_enabled' => false,
            'twig_enabled' => false,
            'monolog_enabled' => false,
            'log_export_enabled' => false,
        ]);

        self::assertInstanceOf(Tracing::class, $container->get(TracingInterface::class));
    }

    public function testLogExportBootsWithMonologBundle(): void
    {
        $container = $this->boot(
            ['log_export_enabled' => true],
            [new \Symfony\Bundle\MonologBundle\MonologBundle()],
        );

        self::assertInstanceOf(OtelLogHandler::class, $container->get(OtelLogHandler::class));
        self::assertInstanceOf(OtelLoggerFlushSubscriber::class, $container->get(OtelLoggerFlushSubscriber::class));
    }

    public function testLogExportFailsWithoutMonologBundle(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('symfony/monolog-bundle');

        $this->boot(['log_export_enabled' => true]);
    }

    public function testHttpClientExcludedHostsParameter(): void
    {
        $this->boot(['http_client_excluded_hosts' => ['collector.local']]);

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
        $this->boot(['cache_enabled' => false]);

        self::assertFalse(
            $this->kernel->getContainer()->getParameter('open_telemetry.cache_enabled'),
        );
    }

    /**
     * @param array<string, mixed> $otelConfig
     * @param list<\Symfony\Component\HttpKernel\Bundle\BundleInterface> $extraBundles
     */
    private function boot(array $otelConfig = [], array $extraBundles = []): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        $this->kernel = new OpenTelemetryTestKernel($otelConfig, $extraBundles);
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }
}
