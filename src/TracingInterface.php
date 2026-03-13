<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle;

use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Contract for the tracing helper.
 *
 * Typehint against this interface in your services so you can
 * easily swap in a no-op implementation for tests.
 */
interface TracingInterface
{
    /**
     * Execute a callable inside a new span.
     *
     * @template T
     *
     * @param non-empty-string $name Span name (e.g. "db.query", "http.client", "cache.get")
     * @param callable(): T $callback The work to trace
     * @param array<string, mixed> $attributes Span attributes set before the callback runs
     * @param int $kind Span kind (defaults to INTERNAL)
     *
     * @return T The callback's return value
     *
     * @throws Throwable
     */
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
    ): mixed;
}
