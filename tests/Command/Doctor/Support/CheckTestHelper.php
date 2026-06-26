<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Command\Doctor\Support;

use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\EnvReaderInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\GlobalsAccessorInterface;

final class CheckTestHelper
{
    /**
     * @param array<string, string> $env
     * @param array<string, mixed>  $params
     */
    public static function context(
        array $env = [],
        array $params = [],
        ?TracerProviderInterface $tracer = null,
        ?MeterProviderInterface $meter = null,
        ?LoggerProviderInterface $logger = null,
        ?TextMapPropagatorInterface $propagator = null,
        bool $skipNetwork = false,
        float $networkTimeoutSeconds = 1.0,
    ): CheckContext {
        return new CheckContext(
            new ParameterBag($params),
            self::envStub($env),
            self::globalsStub($tracer, $meter, $logger, $propagator),
            $skipNetwork,
            $networkTimeoutSeconds,
        );
    }

    /**
     * @param array<string, string> $values
     */
    public static function envStub(array $values): EnvReaderInterface
    {
        return new class($values) implements EnvReaderInterface {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function get(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }

            public function has(string $name): bool
            {
                return isset($this->values[$name]);
            }
        };
    }

    public static function globalsStub(
        ?TracerProviderInterface $tracer = null,
        ?MeterProviderInterface $meter = null,
        ?LoggerProviderInterface $logger = null,
        ?TextMapPropagatorInterface $propagator = null,
    ): GlobalsAccessorInterface {
        return new class($tracer ?? new NoopTracerProvider(), $meter ?? new NoopMeterProvider(), $logger ?? new NoopLoggerProvider(), $propagator ?? NoopTextMapPropagator::getInstance()) implements GlobalsAccessorInterface {
            public function __construct(
                private readonly TracerProviderInterface $tracer,
                private readonly MeterProviderInterface $meter,
                private readonly LoggerProviderInterface $logger,
                private readonly TextMapPropagatorInterface $propagator,
            ) {
            }

            public function tracerProvider(): TracerProviderInterface
            {
                return $this->tracer;
            }

            public function meterProvider(): MeterProviderInterface
            {
                return $this->meter;
            }

            public function loggerProvider(): LoggerProviderInterface
            {
                return $this->logger;
            }

            public function propagator(): TextMapPropagatorInterface
            {
                return $this->propagator;
            }
        };
    }
}
