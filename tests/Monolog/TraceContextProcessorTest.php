<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Monolog;

use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\Span;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Monolog\TraceContextProcessor;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceContextProcessorTest extends TestCase
{
    use OTelTestTrait;

    private TraceContextProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->processor = new TraceContextProcessor();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testInjectsTraceIdAndSpanIdIntoLogRecord(): void
    {
        $tracer = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('test-span')->startSpan();
        $scope = $span->activate();

        try {
            $record = $this->createLogRecord('Test message');
            $processed = ($this->processor)($record);

            self::assertArrayHasKey('trace_id', $processed->extra);
            self::assertArrayHasKey('span_id', $processed->extra);
            self::assertSame($span->getContext()->getTraceId(), $processed->extra['trace_id']);
            self::assertSame($span->getContext()->getSpanId(), $processed->extra['span_id']);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function testSkipsInjectionWhenNoActiveSpan(): void
    {
        $record = $this->createLogRecord('No span active');
        $processed = ($this->processor)($record);

        self::assertArrayNotHasKey('trace_id', $processed->extra);
        self::assertArrayNotHasKey('span_id', $processed->extra);
    }

    public function testPreservesExistingExtraFields(): void
    {
        $tracer = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('test-span')->startSpan();
        $scope = $span->activate();

        try {
            $record = $this->createLogRecord('Test', ['request_id' => 'abc-123']);
            $processed = ($this->processor)($record);

            self::assertSame('abc-123', $processed->extra['request_id']);
            self::assertArrayHasKey('trace_id', $processed->extra);
            self::assertArrayHasKey('span_id', $processed->extra);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function testOriginalRecordIsNotMutated(): void
    {
        $tracer = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('test-span')->startSpan();
        $scope = $span->activate();

        try {
            $record = $this->createLogRecord('Immutable check');
            $processed = ($this->processor)($record);

            self::assertNotSame($record, $processed);
            self::assertArrayNotHasKey('trace_id', $record->extra);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function testTraceIdFormatIs32HexChars(): void
    {
        $tracer = \OpenTelemetry\API\Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('format-check')->startSpan();
        $scope = $span->activate();

        try {
            $record = $this->createLogRecord('Format test');
            $processed = ($this->processor)($record);

            /** @var string $traceId */
            $traceId = $processed->extra['trace_id'];
            /** @var string $spanId */
            $spanId = $processed->extra['span_id'];

            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spanId);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createLogRecord(string $message, array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            extra: $extra,
        );
    }
}
