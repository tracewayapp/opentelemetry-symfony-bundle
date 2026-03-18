<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Messenger;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * OpenTelemetry middleware for Symfony Messenger.
 *
 * On dispatch: injects the current trace context into the envelope as a
 * {@see TraceContextStamp} so it survives serialization across transports.
 *
 * On consume: extracts the stamp and creates a CONSUMER span. When
 * {@see $rootSpans} is true the span has no parent, so task-oriented
 * backends (e.g. Traceway, Sentry) classify it as an independent job.
 */
final class OpenTelemetryMiddleware implements MiddlewareInterface
{
    private ?TracerInterface $tracer = null;

    /**
     * @param string $tracerName Instrumentation library name
     * @param bool   $rootSpans  When true, consumed messages create root spans (no parent)
     *                           so task-oriented backends classify them as independent jobs
     */
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        private readonly bool $rootSpans = false,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($this->isConsuming($envelope)) {
            return $this->handleConsume($envelope, $stack);
        }

        return $this->handleDispatch($envelope, $stack);
    }

    /**
     * Dispatch side: inject current trace context into the envelope.
     */
    private function handleDispatch(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(TraceContextStamp::class)) {
            $carrier = [];
            Globals::propagator()->inject($carrier);

            $headers = [];
            if (\is_array($carrier)) {
                foreach ($carrier as $key => $value) {
                    if (\is_string($key) && \is_string($value)) {
                        $headers[$key] = $value;
                    }
                }
            }

            if ([] !== $headers) {
                $envelope = $envelope->with(new TraceContextStamp($headers));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * Consume side: create a span for the handled message.
     *
     * When rootSpans is false (default), the span is linked to the dispatching
     * trace via the stamp — standard distributed tracing behavior.
     *
     * When rootSpans is true, the span is created with no parent so task-oriented
     * backends (Traceway, Sentry) classify it as an independent job/task.
     */
    private function handleConsume(Envelope $envelope, StackInterface $stack): Envelope
    {
        $messageClass = $envelope->getMessage()::class;
        $spanName = $this->resolveSpanName($messageClass);

        $tracer = $this->getTracer();

        $parentContext = Context::getRoot();

        /** @var TraceContextStamp|null $stamp */
        $stamp = $envelope->last(TraceContextStamp::class);
        if (null !== $stamp && !$this->rootSpans) {
            $parentContext = Globals::propagator()->extract($stamp->getHeaders());
        }

        $span = $tracer->spanBuilder($spanName)
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.system', 'symfony_messenger')
            ->setAttribute('messaging.operation.type', 'process')
            ->setAttribute('messaging.message.class', $messageClass)
            ->startSpan();

        $scope = $span->activate();

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            $span->setStatus(StatusCode::STATUS_OK);

            return $envelope;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    private function isConsuming(Envelope $envelope): bool
    {
        return null !== $envelope->last(ReceivedStamp::class)
            || null !== $envelope->last(ConsumedByWorkerStamp::class);
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }

    /**
     * @return non-empty-string
     */
    private function resolveSpanName(string $messageClass): string
    {
        $pos = strrpos($messageClass, '\\');

        return sprintf('%s process', false !== $pos ? substr($messageClass, $pos + 1) : $messageClass);
    }
}
