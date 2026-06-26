<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\XRay;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wires AWS X-Ray ID generator and/or propagator into the OpenTelemetry globals
 * before any instrumentation subscriber creates its first span.
 *
 * Subscribing to kernel.request / console.command at PHP_INT_MAX forces early
 * construction of this service so that Globals::registerInitializer() is called
 * before OpenTelemetrySubscriber (priority 256) or ConsoleSubscriber (priority 128)
 * touch Globals::tracerProvider() for the first time.
 */
final class XRayBootstrapper implements EventSubscriberInterface
{
    use LogsMessagesTrait;

    public function __construct(string $propagator, string $idGenerator)
    {
        if (!class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class)) {
            throw new \LogicException('X-Ray support requires "open-telemetry/contrib-aws". Run: composer require open-telemetry/contrib-aws');
        }

        Globals::registerInitializer(
            function (Configurator $configurator) use ($propagator, $idGenerator): Configurator {
                if ('xray' === $idGenerator) {
                    $configurator = $configurator->withTracerProvider(
                        $this->buildXRayTracerProvider()
                    );
                }

                if ('w3c' !== $propagator) {
                    $xray = new \OpenTelemetry\Contrib\Aws\Xray\Propagator();
                    $p = 'xray' === $propagator
                        ? $xray
                        : new MultiTextMapPropagator([TraceContextPropagator::getInstance(), $xray]);
                    $configurator = $configurator->withPropagator($p);
                }

                return $configurator;
            }
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['boot', \PHP_INT_MAX],
            ConsoleEvents::COMMAND => ['boot', \PHP_INT_MAX],
        ];
    }

    public function boot(): void
    {
    }

    private function buildXRayTracerProvider(): TracerProvider
    {
        $exporter = null;
        try {
            $exporter = (new ExporterFactory())->create();
        } catch (\Throwable $e) {
            self::logWarning('XRay: unable to create span exporter from OTEL_TRACES_EXPORTER', ['exception' => $e]);
        }

        $sampler = null;
        try {
            $sampler = (new SamplerFactory())->create();
        } catch (\Throwable $e) {
            self::logWarning('XRay: unable to create sampler from OTEL_TRACES_SAMPLER', ['exception' => $e]);
        }

        $spanProcessor = (new SpanProcessorFactory())->create($exporter);

        return new TracerProvider(
            $spanProcessor,
            $sampler,
            null,
            null,
            new \OpenTelemetry\Contrib\Aws\Xray\IdGenerator(),
        );
    }
}
