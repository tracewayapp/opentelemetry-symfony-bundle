<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Monolog;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\Severity;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

/**
 * Monolog handler that exports log records via the OpenTelemetry Logs API.
 *
 * Each Monolog channel becomes its own OTel instrumentation scope, matching
 * how the official opentelemetry-logger-monolog contrib handler does it.
 * Trace context is correlated by the SDK from the active span on emit.
 */
final class OtelLogHandler extends AbstractProcessingHandler implements ResetInterface
{
    /**
     * Keys written by Monolog's IntrospectionProcessor. Promoted to OTel canonical
     * code.* attributes (semconv Stable) and dropped from the monolog.extra.* namespace
     * to avoid duplication.
     */
    private const INTROSPECTION_EXTRA_KEYS = ['file', 'line', 'class', 'callType', 'function'];

    /** @var array<string, LoggerInterface> */
    private array $loggers = [];
    private readonly NormalizerFormatter $normalizer;

    /**
     * Guards against recursion when the OTel export path itself produces a log record
     * (e.g. the OTLP HTTP exporter logging on a failed send). Without this, SimpleLogRecordProcessor
     * turns such a log into an infinite loop, and BatchLogRecordProcessor's forceFlush loses records.
     */
    private bool $emitting = false;

    public function __construct(
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly bool $captureCodeAttributes = false,
        private readonly bool $unprefixedAttributes = false,
    ) {
        parent::__construct($level, $bubble);
        $this->normalizer = new NormalizerFormatter();
    }

    protected function write(LogRecord $record): void
    {
        if ($this->emitting) {
            return;
        }

        $this->emitting = true;
        try {
            $builder = $this->getLogger($record->channel)
                ->logRecordBuilder()
                ->setTimestamp(((int) $record->datetime->format('Uu')) * 1000)
                ->setSeverityNumber(Severity::fromPsr3($record->level->toPsrLogLevel()))
                ->setSeverityText($record->level->getName())
                ->setBody($record->message);

            $contextPrefix = $this->unprefixedAttributes ? '' : 'monolog.context.';
            $extraPrefix = $this->unprefixedAttributes ? '' : 'monolog.extra.';

            foreach ($record->context as $key => $value) {
                if ('exception' === $key && $value instanceof \Throwable) {
                    $builder->setException($value);
                    continue;
                }
                $builder->setAttribute($contextPrefix . $key, $this->toAttributeValue($value));
            }

            [$file, $line, $function] = $this->resolveCodeAttributes($record->extra);
            if (null !== $file) {
                $builder->setAttribute('code.file.path', $file);
            }
            if (null !== $line) {
                $builder->setAttribute('code.line.number', $line);
            }
            if (null !== $function) {
                $builder->setAttribute('code.function.name', $function);
            }

            foreach ($record->extra as $key => $value) {
                // The SDK sets trace_id/span_id natively on the LogRecord from the active span.
                // TraceContextProcessor also writes them into extra for non-OTel handlers — skip the duplicate here.
                if ('trace_id' === $key || 'span_id' === $key) {
                    continue;
                }
                if (\in_array($key, self::INTROSPECTION_EXTRA_KEYS, true)) {
                    continue;
                }
                $builder->setAttribute($extraPrefix . $key, $this->toAttributeValue($value));
            }

            $builder->emit();
        } catch (\Throwable $e) {
            // A logging handler must never take down the thing being logged about.
            error_log(sprintf('OtelLogHandler: failed to export log record: %s', $e->getMessage()));
        } finally {
            $this->emitting = false;
        }
    }

    public function reset(): void
    {
        parent::reset();
        $this->loggers = [];
        $this->emitting = false;
    }

    private function getLogger(string $channel): LoggerInterface
    {
        return $this->loggers[$channel] ??= Globals::loggerProvider()->getLogger(
            $channel,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }

    /**
     * Resolves OTel canonical code.* attributes (semconv Stable):
     * https://opentelemetry.io/docs/specs/semconv/attributes-registry/code/
     *
     * Source 1 (free): Monolog's IntrospectionProcessor writes file/line/class/function into
     * $record->extra. We promote them to code.file.path / code.line.number / code.function.name
     * (formatted Class::function per the spec example "GuzzleHttp\Client::transfer").
     *
     * Source 2 (opt-in): when captureCodeAttributes is true and the processor extras aren't
     * populated, walk debug_backtrace skipping Monolog and bundle frames to recover the call site.
     *
     * @param array<array-key, mixed> $extra
     * @return array{0: ?string, 1: ?int, 2: ?string} [file, line, function-name]
     */
    private function resolveCodeAttributes(array $extra): array
    {
        $file = \is_string($extra['file'] ?? null) ? $extra['file'] : null;
        $line = \is_int($extra['line'] ?? null) ? $extra['line'] : null;
        $class = \is_string($extra['class'] ?? null) ? $extra['class'] : null;
        $function = \is_string($extra['function'] ?? null) ? $extra['function'] : null;

        if ($this->captureCodeAttributes && null === $file && null === $line && null === $function) {
            [$file, $line, $class, $function] = $this->resolveFromBacktrace();
        }

        $functionName = null;
        if (null !== $function) {
            $functionName = null !== $class ? $class . '::' . $function : $function;
        }

        return [$file, $line, $functionName];
    }

    /**
     * Mirrors Monolog\Processor\IntrospectionProcessor::isTraceClassOrSkippedFunction: walk past
     * Monolog internals and this bundle's handler frames to find the application call site.
     *
     * @return array{0: ?string, 1: ?int, 2: ?string, 3: ?string} [file, line, class, function]
     */
    private function resolveFromBacktrace(): array
    {
        $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
        $count = \count($trace);

        $i = 0;
        while ($i < $count) {
            $cls = $trace[$i]['class'] ?? '';
            if (
                str_starts_with($cls, 'Monolog\\')
                || str_starts_with($cls, 'Traceway\\OpenTelemetryBundle\\Monolog\\')
                || str_starts_with($cls, 'Symfony\\Bridge\\Monolog\\')
            ) {
                ++$i;
                continue;
            }
            break;
        }

        if ($i === 0 || $i >= $count) {
            return [null, null, null, null];
        }

        $appFrame = $trace[$i];
        $callFrame = $trace[$i - 1];

        return [
            $callFrame['file'] ?? null,
            $callFrame['line'] ?? null,
            $appFrame['class'] ?? null,
            $appFrame['function'],
        ];
    }

    /**
     * @return bool|int|float|string|array<int, bool|int|float|string|null>|null
     */
    private function toAttributeValue(mixed $value): bool|int|float|string|array|null
    {
        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        $normalized = $this->normalizer->normalizeValue($value);

        if (null === $normalized || \is_scalar($normalized)) {
            return $normalized;
        }

        if (array_is_list($normalized)) {
            $scalarList = [];
            foreach ($normalized as $item) {
                if (null !== $item && !\is_scalar($item)) {
                    $scalarList = null;
                    break;
                }
                $scalarList[] = $item;
            }
            if (null !== $scalarList) {
                return $scalarList;
            }
        }

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[unserializable]';
    }
}
