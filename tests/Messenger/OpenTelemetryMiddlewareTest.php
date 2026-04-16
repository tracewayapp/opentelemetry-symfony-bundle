<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Messenger;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\TraceContextStamp;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class OpenTelemetryMiddlewareTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testDispatchCreatesProducerSpan(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass());

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('stdClass publish', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_PRODUCER, $spans[0]->getKind());
        self::assertSame(StatusCode::STATUS_OK, $spans[0]->getStatus()->getCode());

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('symfony_messenger', $attributes['messaging.system']);
        self::assertSame('publish', $attributes['messaging.operation.type']);
        self::assertSame(\stdClass::class, $attributes['messaging.message.class']);
    }

    public function testDispatchInjectsTraceContextStamp(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('dispatch')->startSpan();
        $scope = $span->activate();

        try {
            $middleware = new OpenTelemetryMiddleware('test');
            $envelope = new Envelope(new \stdClass());

            $stack = new StackMiddleware();
            $result = $middleware->handle($envelope, $stack);

            $stamp = $result->last(TraceContextStamp::class);
            self::assertInstanceOf(TraceContextStamp::class, $stamp);
            self::assertNotEmpty($stamp->getHeaders());
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    public function testDispatchDoesNotOverwriteExistingStamp(): void
    {
        $existingHeaders = ['traceparent' => '00-existing-trace-01'];
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new TraceContextStamp($existingHeaders)]);

        $stack = new StackMiddleware();
        $result = $middleware->handle($envelope, $stack);

        $stamp = $result->last(TraceContextStamp::class);
        self::assertSame($existingHeaders, $stamp->getHeaders());
    }

    public function testDispatchRecordsExceptionOnFailure(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass());

        $failingMiddleware = new class implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
            {
                throw new \RuntimeException('dispatch failed');
            }
        };

        $stack = new StackMiddleware([$middleware, $failingMiddleware]);

        try {
            $middleware->handle($envelope, $stack);
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(SpanKind::KIND_PRODUCER, $spans[0]->getKind());
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('dispatch failed', $spans[0]->getStatus()->getDescription());
    }

    public function testConsumeCreatesSpan(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('sync')]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertStringContainsString('process', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CONSUMER, $spans[0]->getKind());
        self::assertSame(StatusCode::STATUS_OK, $spans[0]->getStatus()->getCode());
    }

    public function testConsumeSpanHasMessagingAttributes(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();

        self::assertSame('symfony_messenger', $attributes['messaging.system']);
        self::assertSame('process', $attributes['messaging.operation.type']);
        self::assertSame(\stdClass::class, $attributes['messaging.message.class']);
        self::assertSame('async', $attributes['messaging.destination.name']);
    }

    public function testConsumeRecordsException(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('sync')]);

        $failingMiddleware = new class implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
            {
                throw new \RuntimeException('handler failed');
            }
        };

        $stack = new StackMiddleware([$middleware, $failingMiddleware]);

        try {
            $middleware->handle($envelope, $stack);
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('handler failed', $spans[0]->getStatus()->getDescription());
    }

    public function testRootSpansCreatesDetachedTrace(): void
    {
        $middleware = new OpenTelemetryMiddleware('test', rootSpans: true);

        $headers = ['traceparent' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01'];
        $envelope = new Envelope(new \stdClass(), [
            new ReceivedStamp('sync'),
            new TraceContextStamp($headers),
        ]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);

        // With rootSpans=true, the span should NOT have the parent from the stamp
        self::assertNotSame(str_repeat('a', 32), $spans[0]->getContext()->getTraceId());
    }

    public function testNonRootSpansLinksToParentTrace(): void
    {
        $middleware = new OpenTelemetryMiddleware('test', rootSpans: false);

        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);
        $headers = ['traceparent' => "00-{$traceId}-{$spanId}-01"];
        $envelope = new Envelope(new \stdClass(), [
            new ReceivedStamp('sync'),
            new TraceContextStamp($headers),
        ]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);

        // With rootSpans=false, the span should be a child of the propagated trace
        self::assertSame($traceId, $spans[0]->getContext()->getTraceId());
        self::assertSame($spanId, $spans[0]->getParentSpanId());
    }

    public function testResolveSpanNameUsesShortClassName(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('sync')]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        self::assertSame('stdClass process', $spans[0]->getName());
    }

    public function testConsumedByWorkerStampTriggersConsumeSpan(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ConsumedByWorkerStamp()]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertStringContainsString('process', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CONSUMER, $spans[0]->getKind());
    }

    public function testResetClearsTracerCache(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass());

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $middleware->reset();

        $envelope2 = new Envelope(new \stdClass());
        $stack2 = new StackMiddleware();
        $middleware->handle($envelope2, $stack2);

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
    }

    public function testConsumeWithoutReceivedStampOmitsDestination(): void
    {
        $middleware = new OpenTelemetryMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ConsumedByWorkerStamp()]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey('messaging.destination.name', $attributes);
    }

    public function testConsumeWithEmptyTraceContextStamp(): void
    {
        $middleware = new OpenTelemetryMiddleware('test', rootSpans: false);
        $envelope = new Envelope(new \stdClass(), [
            new ReceivedStamp('sync'),
            new TraceContextStamp([]),
        ]);

        $stack = new StackMiddleware();
        $middleware->handle($envelope, $stack);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_OK, $spans[0]->getStatus()->getCode());
    }
}
