<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Lightweight helper for creating OpenTelemetry spans with minimal boilerplate.
 *
 * Inject this service wherever you need manual instrumentation:
 *
 *     $this->tracing->trace('cache.get', function () use ($key) {
 *         return $this->redis->get($key);
 *     }, attributes: ['cache.key' => $key]);
 */
final class Tracing implements TracingInterface
{
    private ?TracerInterface $tracer = null;

    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
    ) {}

    /**
     * {@inheritDoc}
     *
     * @param non-empty-string $name
     * @param SpanKind::KIND_* $kind
     */
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
    ): mixed {
        $span = $this->getTracer()
            ->spanBuilder($name)
            ->setSpanKind($kind)
            ->setAttributes($attributes)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $callback();
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }
}
