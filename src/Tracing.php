<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Contracts\Service\ResetInterface;

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
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

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

    public function reset(): void
    {
        $this->tracer = null;
        $this->enabled = null;
    }

    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer(
            $this->tracerName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }
}
