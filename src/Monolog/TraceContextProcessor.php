<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * Injects the current OpenTelemetry trace_id and span_id into every Monolog log record.
 * Enables log-trace correlation: jump from a log line to the matching trace in your backend.
 */
final class TraceContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $spanContext = Span::getCurrent()->getContext();
        $traceId = $spanContext->getTraceId();
        $spanId = $spanContext->getSpanId();

        if ('00000000000000000000000000000000' === $traceId) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, [
            'trace_id' => $traceId,
            'span_id' => $spanId,
        ]));
    }
}
