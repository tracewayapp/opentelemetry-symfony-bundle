<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Instrumentation\TracerAwareTrait;

/**
 * Lightweight helper for creating OpenTelemetry spans with minimal boilerplate.
 *
 * Inject this service wherever you need manual instrumentation:
 *
 *     $this->tracing->trace('cache.get', function () use ($key) {
 *         return $this->redis->get($key);
 *     }, attributes: ['cache.key' => $key]);
 */
final class Tracing implements TracingInterface, ResetInterface
{
    use TracerAwareTrait;

    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
    ) {
    }

    /**
     * @param non-empty-string $name
     * @param SpanKind::KIND_* $kind
     */
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
    ): mixed {
        if (!$this->isEnabled()) {
            return $callback();
        }

        $span = $this->getTracer()
            ->spanBuilder($name)
            ->setSpanKind($kind)
            ->setAttributes($attributes)
            ->startSpan();

        $scope = $span->activate();

        try {
            // Status stays UNSET on success, per the OTel spec for instrumentation.
            return $callback();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    public function reset(): void
    {
        $this->resetTracer();
    }
}
