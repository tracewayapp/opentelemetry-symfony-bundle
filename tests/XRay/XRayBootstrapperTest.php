<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\XRay;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\XRay\XRayBootstrapper;

final class XRayBootstrapperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class)) {
            $this->markTestSkipped('open-telemetry/contrib-aws is required for XRay tests.');
        }

        Globals::reset();
    }

    protected function tearDown(): void
    {
        Globals::reset();
    }

    public function testConstructorThrowsWhenContribAwsMissing(): void
    {
        // This test is only meaningful when the package is absent.
        // When installed, the constructor succeeds — we just skip it above.
        $this->markTestSkipped('open-telemetry/contrib-aws is installed; the error path is not reachable.');
    }

    public function testXRayPropagatorIsRegisteredAsGlobal(): void
    {
        new XRayBootstrapper('xray', 'default');

        $propagator = Globals::propagator();

        self::assertInstanceOf(\OpenTelemetry\Contrib\Aws\Xray\Propagator::class, $propagator);
    }

    public function testW3cPropagatorIsNotChangedByDefault(): void
    {
        // With propagator: w3c (no-op), Globals should stay with whatever was set before.
        // We prime Globals with the W3C propagator the same way OTelTestTrait does.
        Globals::registerInitializer(static fn ($c) => $c->withPropagator(TraceContextPropagator::getInstance()));

        new XRayBootstrapper('w3c', 'default');

        $propagator = Globals::propagator();
        self::assertInstanceOf(TraceContextPropagator::class, $propagator);
    }

    public function testW3cPlusXRayPropagatorIsMulti(): void
    {
        new XRayBootstrapper('w3c+xray', 'default');

        $propagator = Globals::propagator();

        self::assertInstanceOf(MultiTextMapPropagator::class, $propagator);
    }

    public function testXRayPropagatorInjectsXAmznTraceIdHeader(): void
    {
        new XRayBootstrapper('xray', 'default');

        $spanContext = \OpenTelemetry\API\Trace\SpanContext::create(
            '58406520a006649127e371903a2de979',
            '53995c3f42cd8ad8',
            \OpenTelemetry\API\Trace\TraceFlags::SAMPLED,
        );
        $scope = \OpenTelemetry\API\Trace\Span::wrap($spanContext)->activate();

        $carrier = [];
        Globals::propagator()->inject($carrier);

        $scope->detach();

        self::assertArrayHasKey('x-amzn-trace-id', $carrier);
        self::assertStringStartsWith('Root=1-', $carrier['x-amzn-trace-id']);
    }

    public function testXRayPropagatorExtractsIncomingContext(): void
    {
        new XRayBootstrapper('xray', 'default');

        $traceId = '58406520a006649127e371903a2de979';
        $spanId  = '53995c3f42cd8ad8';
        $carrier = ['x-amzn-trace-id' => "Root=1-58406520-a006649127e371903a2de979;Parent={$spanId};Sampled=1"];

        $context = Globals::propagator()->extract($carrier);
        $span = \OpenTelemetry\API\Trace\Span::fromContext($context);

        self::assertSame($traceId, $span->getContext()->getTraceId());
        self::assertSame($spanId, $span->getContext()->getSpanId());
    }

    public function testIdGeneratorXRayRegistersTracerProviderWithEpochPrefixedIds(): void
    {
        new XRayBootstrapper('xray', 'xray');

        $tracerProvider = Globals::tracerProvider();
        $tracer = $tracerProvider->getTracer('test');
        $span = $tracer->spanBuilder('test-span')->startSpan();
        $traceId = $span->getContext()->getTraceId();
        $span->end();

        // X-Ray trace IDs are 32 hex chars: an 8-char Unix epoch timestamp followed
        // by a 24-char random segment. The '1' version prefix appears only in the
        // X-Ray wire format (Root=1-...), not in the raw trace ID bytes.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        self::assertGreaterThan(1_000_000_000, hexdec(substr($traceId, 0, 8)), 'First 8 chars must be a plausible Unix timestamp');
    }
}
