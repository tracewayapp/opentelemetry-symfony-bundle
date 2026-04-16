<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Monolog;

use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class OtelLogHandlerTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        if (!class_exists(\Monolog\Logger::class)) {
            self::markTestSkipped('Monolog not available.');
        }

        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testExportsLogWithCorrectBodyAndSeverity(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'Something went wrong',
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        self::assertCount(1, $logs);

        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        self::assertSame('Something went wrong', $log->getBody());
        self::assertSame(Severity::WARN->value, $log->getSeverityNumber());
        self::assertSame('WARNING', $log->getSeverityText());
    }

    public function testExportsLogWithTimestamp(): void
    {
        $handler = new OtelLogHandler();
        $datetime = new \DateTimeImmutable('2026-04-10 12:00:00.123456');

        $record = new LogRecord(
            datetime: $datetime,
            channel: 'app',
            level: Level::Info,
            message: 'test',
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $expectedNanos = ((int) $datetime->format('Uu')) * 1000;
        self::assertSame($expectedNanos, $log->getTimestamp());
    }

    public function testTraceCorrelationWhenSpanIsActive(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('test-span')->startSpan();
        $scope = $span->activate();

        try {
            $handler = new OtelLogHandler();
            $record = new LogRecord(
                datetime: new \DateTimeImmutable(),
                channel: 'app',
                level: Level::Info,
                message: 'inside span',
            );
            $handler->handle($record);
        } finally {
            $scope->detach();
            $span->end();
        }

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];

        $spanContext = $log->getSpanContext();
        self::assertNotNull($spanContext);
        self::assertNotSame(SpanContext::getInvalid()->getTraceId(), $spanContext->getTraceId());
        self::assertNotSame(SpanContext::getInvalid()->getSpanId(), $spanContext->getSpanId());
    }

    public function testForwardsScalarContextAsAttributes(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['user_id' => 42, 'action' => 'login'],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame(42, $attrs['monolog.context.user_id']);
        self::assertSame('login', $attrs['monolog.context.action']);
    }

    public function testForwardsListOfScalarsAsArrayAttribute(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['tags' => ['alpha', 'beta', 'gamma']],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame(['alpha', 'beta', 'gamma'], $attrs['monolog.context.tags']);
    }

    public function testJsonEncodesNonScalarContextValue(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['user' => ['id' => 42, 'name' => 'alice']],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertIsString($attrs['monolog.context.user']);
        self::assertSame(['id' => 42, 'name' => 'alice'], json_decode($attrs['monolog.context.user'], true));
    }

    public function testSkipsTraceIdAndSpanIdInExtra(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'trace_id' => 'deadbeef',
                'span_id'  => 'cafef00d',
                'request_id' => 'abc-123',
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertArrayNotHasKey('monolog.extra.trace_id', $attrs);
        self::assertArrayNotHasKey('monolog.extra.span_id', $attrs);
        self::assertSame('abc-123', $attrs['monolog.extra.request_id']);
    }

    public function testExceptionInContextSetsExceptionAttributes(): void
    {
        $handler = new OtelLogHandler();
        $exception = new \RuntimeException('Something broke');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'Uncaught exception',
            context: ['exception' => $exception, 'code' => 500],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame(\RuntimeException::class, $attrs['exception.type']);
        self::assertSame('Something broke', $attrs['exception.message']);
        self::assertArrayHasKey('exception.stacktrace', $attrs);
        self::assertSame(500, $attrs['monolog.context.code']);
        self::assertArrayNotHasKey('monolog.context.exception', $attrs);
    }

    public function testForwardsScalarExtraAsAttributes(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: ['request_id' => 'abc-123'],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('abc-123', $attrs['monolog.extra.request_id']);
    }

    public function testSetsChannelAttribute(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'security',
            level: Level::Info,
            message: 'test',
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        self::assertSame('security', $log->getAttributes()->toArray()['monolog.channel']);
    }

    public function testRespectsLevelFilter(): void
    {
        $handler = new OtelLogHandler(Level::Error);

        $debugRecord = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Debug,
            message: 'debug message',
        );

        $errorRecord = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'error message',
        );

        $handler->handle($debugRecord);
        $handler->handle($errorRecord);

        $logs = $this->logExporter->getStorage();
        self::assertCount(1, $logs);

        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        self::assertSame('error message', $log->getBody());
    }

    public function testEachChannelBecomesAnInstrumentationScope(): void
    {
        $handler = new OtelLogHandler();

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'from app',
        ));
        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'security',
            level: Level::Info,
            message: 'from security',
        ));

        $logs = $this->logExporter->getStorage();
        self::assertCount(2, $logs);

        /** @var ReadableLogRecord $appLog */
        $appLog = $logs[0];
        /** @var ReadableLogRecord $securityLog */
        $securityLog = $logs[1];

        self::assertSame('app', $appLog->getInstrumentationScope()->getName());
        self::assertSame('security', $securityLog->getInstrumentationScope()->getName());
    }

    public function testTimestampPreservesMicrosecondPrecision(): void
    {
        $handler = new OtelLogHandler();
        $datetime = new \DateTimeImmutable('2026-04-11 13:55:56.890028');

        $handler->handle(new LogRecord(
            datetime: $datetime,
            channel: 'app',
            level: Level::Info,
            message: 'precision test',
        ));

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];

        $expectedNanos = ((int) $datetime->format('Uu')) * 1000;
        self::assertSame($expectedNanos, $log->getTimestamp());
        self::assertSame(890028000, $expectedNanos % 1_000_000_000);
    }

    public function testReentranceGuardDropsNestedWrites(): void
    {
        $handler = new OtelLogHandler();

        $reflection = new \ReflectionClass($handler);
        $emitting = $reflection->getProperty('emitting');
        $emitting->setValue($handler, true);

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'nested write during emit',
        ));

        self::assertCount(0, $this->logExporter->getStorage());
        self::assertTrue($emitting->getValue($handler));
    }

    public function testMixedTypeListFallsBackToJsonEncoding(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['items' => ['text', 42, ['nested' => true]]],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertIsString($attrs['monolog.context.items']);
        $decoded = json_decode($attrs['monolog.context.items'], true);
        self::assertSame('text', $decoded[0]);
        self::assertSame(42, $decoded[1]);
    }

    public function testNullValueInContextIsPassedAsAttribute(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['empty' => null, 'present' => 'yes'],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('yes', $attrs['monolog.context.present']);
    }

    public function testListOfScalarsWithNullsPreserved(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['ids' => [1, null, 3]],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame([1, null, 3], $attrs['monolog.context.ids']);
    }

    public function testResetClearsCachedLogger(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'before reset',
        );

        $handler->handle($record);
        self::assertCount(1, $this->logExporter->getStorage());

        $handler->reset();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'after reset',
        );

        $handler->handle($record);
        self::assertCount(2, $this->logExporter->getStorage());
    }
}
