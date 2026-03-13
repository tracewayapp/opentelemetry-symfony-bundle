<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\TracingInterface;

final class TracingTest extends TestCase
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

    public function testImplementsInterface(): void
    {
        $tracing = new Tracing();
        self::assertInstanceOf(TracingInterface::class, $tracing);
    }

    public function testTraceCreatesSpanAndReturnsValue(): void
    {
        $tracing = new Tracing('test-tracer');

        $result = $tracing->trace('my.operation', fn () => 42);

        self::assertSame(42, $result);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('my.operation', $spans[0]->getName());
        self::assertSame(StatusCode::STATUS_OK, $spans[0]->getStatus()->getCode());
    }

    public function testTraceRecordsAttributes(): void
    {
        $tracing = new Tracing('test-tracer');

        $tracing->trace('db.query', fn () => null, [
            'db.system' => 'mysql',
            'db.statement' => 'SELECT 1',
        ]);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('mysql', $attributes['db.system']);
        self::assertSame('SELECT 1', $attributes['db.statement']);
    }

    public function testTraceUsesSpanKind(): void
    {
        $tracing = new Tracing('test-tracer');

        $tracing->trace('http.request', fn () => null, [], SpanKind::KIND_CLIENT);

        $spans = $this->exporter->getSpans();
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
    }

    public function testTraceDefaultsToInternalKind(): void
    {
        $tracing = new Tracing('test-tracer');

        $tracing->trace('internal.op', fn () => null);

        $spans = $this->exporter->getSpans();
        self::assertSame(SpanKind::KIND_INTERNAL, $spans[0]->getKind());
    }

    public function testTraceRecordsExceptionAndRethrows(): void
    {
        $tracing = new Tracing('test-tracer');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $tracing->trace('failing.op', fn () => throw new \RuntimeException('boom'));
        } finally {
            $spans = $this->exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
            self::assertSame('boom', $spans[0]->getStatus()->getDescription());

            $events = $spans[0]->getEvents();
            self::assertCount(1, $events);
            self::assertSame('exception', $events[0]->getName());
        }
    }

    public function testNestedTracesCreateParentChild(): void
    {
        $tracing = new Tracing('test-tracer');

        $tracing->trace('parent', function () use ($tracing) {
            $tracing->trace('child', fn () => null);
        });

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);

        $childSpan = $spans[0];
        $parentSpan = $spans[1];

        self::assertSame('child', $childSpan->getName());
        self::assertSame('parent', $parentSpan->getName());

        self::assertSame(
            $parentSpan->getContext()->getTraceId(),
            $childSpan->getContext()->getTraceId(),
        );
        self::assertSame(
            $parentSpan->getContext()->getSpanId(),
            $childSpan->getParentSpanId(),
        );
    }
}
