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
                'span_id' => 'cafef00d',
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

    public function testDoesNotEmitRedundantChannelAttribute(): void
    {
        // The Monolog channel is encoded as the OTel InstrumentationScope name (see
        // testEachChannelBecomesAnInstrumentationScope). Java/Python/.NET/JS do the
        // same and do not duplicate it as an attribute. This guards against regression.
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
        self::assertArrayNotHasKey('monolog.channel', $log->getAttributes()->toArray());
        self::assertSame('security', $log->getInstrumentationScope()->getName());
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

    public function testUnprefixedAttributesFlattensContextAndExtra(): void
    {
        $handler = new OtelLogHandler(unprefixedAttributes: true);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['user_id' => 42, 'action' => 'login'],
            extra: ['request_id' => 'abc-123', 'pid' => 7777],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame(42, $attrs['user_id']);
        self::assertSame('login', $attrs['action']);
        self::assertSame('abc-123', $attrs['request_id']);
        self::assertSame(7777, $attrs['pid']);

        self::assertArrayNotHasKey('monolog.context.user_id', $attrs);
        self::assertArrayNotHasKey('monolog.context.action', $attrs);
        self::assertArrayNotHasKey('monolog.extra.request_id', $attrs);
        self::assertArrayNotHasKey('monolog.extra.pid', $attrs);
    }

    public function testUnprefixedModeStillSuppressesIntrospectionKeysFromExtraNamespace(): void
    {
        // file/line/class/function in extras are promoted to code.* regardless of prefix mode.
        // The raw keys must not leak into the unprefixed attribute namespace either.
        $handler = new OtelLogHandler(unprefixedAttributes: true);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'file' => '/var/www/src/foo.php',
                'line' => 7,
                'class' => 'App\\Foo',
                'function' => 'bar',
                'request_id' => 'keep-me',
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('/var/www/src/foo.php', $attrs['code.file.path']);
        self::assertSame(7, $attrs['code.line.number']);
        self::assertSame('App\\Foo::bar', $attrs['code.function.name']);
        self::assertSame('keep-me', $attrs['request_id']);

        self::assertArrayNotHasKey('file', $attrs);
        self::assertArrayNotHasKey('line', $attrs);
        self::assertArrayNotHasKey('class', $attrs);
        self::assertArrayNotHasKey('function', $attrs);
    }

    public function testUnprefixedModeExtraOverridesContextOnKeyCollision(): void
    {
        // In flat mode, the same key can appear in both context (PSR-3) and extra (Monolog
        // processor metadata). Context is written first, extra last — extra wins. This
        // locks in the precedence so future loop-reordering can't flip it silently.
        $handler = new OtelLogHandler(unprefixedAttributes: true);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['request_id' => 'from-context'],
            extra: ['request_id' => 'from-extra'],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('from-extra', $attrs['request_id']);
    }

    public function testUnprefixedModeStillSkipsTraceIdAndSpanIdInExtra(): void
    {
        $handler = new OtelLogHandler(unprefixedAttributes: true);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'trace_id' => 'deadbeef',
                'span_id' => 'cafef00d',
                'request_id' => 'abc-123',
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertArrayNotHasKey('trace_id', $attrs);
        self::assertArrayNotHasKey('span_id', $attrs);
        self::assertSame('abc-123', $attrs['request_id']);
    }

    public function testPromotesIntrospectionExtrasToCodeAttributes(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'file' => '/var/www/src/Controller/UserController.php',
                'line' => 42,
                'class' => 'App\\Controller\\UserController',
                'callType' => '->',
                'function' => 'show',
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('/var/www/src/Controller/UserController.php', $attrs['code.file.path']);
        self::assertSame(42, $attrs['code.line.number']);
        self::assertSame('App\\Controller\\UserController::show', $attrs['code.function.name']);
    }

    public function testDoesNotEmitDuplicateMonologExtraKeysWhenPromoting(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'file' => '/var/www/src/foo.php',
                'line' => 7,
                'class' => 'App\\Foo',
                'callType' => '::',
                'function' => 'bar',
                'request_id' => 'keep-me',
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertArrayNotHasKey('monolog.extra.file', $attrs);
        self::assertArrayNotHasKey('monolog.extra.line', $attrs);
        self::assertArrayNotHasKey('monolog.extra.class', $attrs);
        self::assertArrayNotHasKey('monolog.extra.callType', $attrs);
        self::assertArrayNotHasKey('monolog.extra.function', $attrs);
        self::assertSame('keep-me', $attrs['monolog.extra.request_id']);
    }

    public function testPromotesBareFunctionWithoutClass(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'file' => '/var/www/script.php',
                'line' => 9,
                'function' => 'top_level_helper',
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('top_level_helper', $attrs['code.function.name']);
    }

    public function testOmitsFunctionAttributeWhenOnlyFileAndLineAvailable(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'file' => '/var/www/script.php',
                'line' => 9,
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('/var/www/script.php', $attrs['code.file.path']);
        self::assertSame(9, $attrs['code.line.number']);
        self::assertArrayNotHasKey('code.function.name', $attrs);
    }

    public function testIgnoresIntrospectionExtrasOfWrongType(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'file' => 123,
                'line' => '42',
                'class' => [],
                'function' => null,
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertArrayNotHasKey('code.file.path', $attrs);
        self::assertArrayNotHasKey('code.line.number', $attrs);
        self::assertArrayNotHasKey('code.function.name', $attrs);
    }

    public function testEmitsNoCodeAttributesByDefaultWhenExtrasAreEmpty(): void
    {
        $handler = new OtelLogHandler();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertArrayNotHasKey('code.file.path', $attrs);
        self::assertArrayNotHasKey('code.line.number', $attrs);
        self::assertArrayNotHasKey('code.function.name', $attrs);
    }

    public function testBacktraceFallbackResolvesCallSiteWhenEnabled(): void
    {
        $handler = new OtelLogHandler(captureCodeAttributes: true);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
        );

        $this->emitThroughBundleFrame($handler, $record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame(self::class.'::emitThroughBundleFrame', $attrs['code.function.name']);
        self::assertSame(__FILE__, $attrs['code.file.path']);
        self::assertIsInt($attrs['code.line.number']);
    }

    public function testExtrasTakePrecedenceOverBacktraceFallback(): void
    {
        $handler = new OtelLogHandler(captureCodeAttributes: true);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            extra: [
                'file' => '/explicit/file.php',
                'line' => 100,
                'class' => 'Explicit\\Class',
                'function' => 'explicit',
            ],
        );

        $handler->handle($record);

        $logs = $this->logExporter->getStorage();
        /** @var ReadableLogRecord $log */
        $log = $logs[0];
        $attrs = $log->getAttributes()->toArray();

        self::assertSame('/explicit/file.php', $attrs['code.file.path']);
        self::assertSame(100, $attrs['code.line.number']);
        self::assertSame('Explicit\\Class::explicit', $attrs['code.function.name']);
    }

    private function emitThroughBundleFrame(OtelLogHandler $handler, LogRecord $record): void
    {
        $handler->handle($record);
    }

    public function testExcludedChannelDropsRecord(): void
    {
        $handler = new OtelLogHandler(excludedChannels: ['deprecation']);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'deprecation',
            level: Level::Warning,
            message: 'User Deprecated: something is deprecated',
        );

        self::assertFalse($handler->isHandling($record));
        $handler->handle($record);

        self::assertCount(0, $this->logExporter->getStorage());
    }

    public function testNonExcludedChannelIsStillExported(): void
    {
        $handler = new OtelLogHandler(excludedChannels: ['deprecation']);

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'real warning',
        ));

        self::assertCount(1, $this->logExporter->getStorage());
    }

    public function testChannelExclusionIsOffByDefault(): void
    {
        $handler = new OtelLogHandler();

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'deprecation',
            level: Level::Warning,
            message: 'User Deprecated: something is deprecated',
        ));

        self::assertCount(1, $this->logExporter->getStorage());
    }

    public function testExcludedHttpCodeDropsRecord(): void
    {
        $handler = new OtelLogHandler(excludedHttpCodes: [404, 405]);

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'request',
            level: Level::Error,
            message: 'Uncaught PHP Exception NotFoundHttpException',
            context: ['exception' => new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('No route found for "GET /.env"')],
        ));

        self::assertCount(0, $this->logExporter->getStorage());
    }

    public function testNonExcludedHttpCodeIsStillExported(): void
    {
        $handler = new OtelLogHandler(excludedHttpCodes: [404]);

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'request',
            level: Level::Error,
            message: 'Access denied',
            context: ['exception' => new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('nope')],
        ));

        $logs = $this->logExporter->getStorage();
        self::assertCount(1, $logs);
    }

    public function testNonHttpExceptionIsExportedDespiteExcludedCodes(): void
    {
        $handler = new OtelLogHandler(excludedHttpCodes: [404, 405]);

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'real error',
            context: ['exception' => new \RuntimeException('boom')],
        ));

        self::assertCount(1, $this->logExporter->getStorage());
    }

    public function testRecordWithoutExceptionIsExportedDespiteExcludedCodes(): void
    {
        $handler = new OtelLogHandler(excludedHttpCodes: [404]);

        $handler->handle(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'plain record',
        ));

        self::assertCount(1, $this->logExporter->getStorage());
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
