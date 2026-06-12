<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Messenger;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * OpenTelemetry middleware for Symfony Messenger.
 *
 * On dispatch: creates a PRODUCER span and injects the current trace context
 * into the envelope as a {@see TraceContextStamp} so it survives serialization
 * across transports.
 *
 * On consume: extracts the stamp and creates a CONSUMER span. When
 * {@see $rootSpans} is true the span has no parent, so task-oriented
 * backends (e.g. Traceway, Sentry) classify it as an independent job.
 */
final class OpenTelemetryMiddleware implements MiddlewareInterface, ResetInterface
{
    private const SCHEDULED_STAMP_CLASS = 'Symfony\\Component\\Scheduler\\Messenger\\ScheduledStamp';

    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    /**
     * @param string $tracerName              Instrumentation library name
     * @param bool   $rootSpans               When true, consumed messages create root spans (no parent)
     *                                        so task-oriented backends classify them as independent jobs
     * @param bool   $excludeScheduledMessages Skip envelopes carrying Symfony's ScheduledStamp on both dispatch and
     *                                        consume paths. Enabled automatically when scheduler instrumentation is
     *                                        active so the richer
     *                                        {@see \Traceway\OpenTelemetryBundle\EventSubscriber\SchedulerSubscriber}
     *                                        span owns scheduled-task observability without duplicate messenger spans.
     */
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        private readonly bool $rootSpans = false,
        private readonly bool $excludeScheduledMessages = false,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->isEnabled()) {
            return $stack->next()->handle($envelope, $stack);
        }

        if ($this->isScheduledMessage($envelope)) {
            return $stack->next()->handle($envelope, $stack);
        }

        if ($this->isConsuming($envelope)) {
            return $this->handleConsume($envelope, $stack);
        }

        return $this->handleDispatch($envelope, $stack);
    }

    private function isScheduledMessage(Envelope $envelope): bool
    {
        if (!$this->excludeScheduledMessages) {
            return false;
        }

        if (!class_exists(self::SCHEDULED_STAMP_CLASS)) {
            return false;
        }

        return null !== $envelope->last(self::SCHEDULED_STAMP_CLASS);
    }

    /**
     * Dispatch side: create a PRODUCER span and inject trace context into the envelope.
     */
    private function handleDispatch(Envelope $envelope, StackInterface $stack): Envelope
    {
        $messageClass = $envelope->getMessage()::class;
        $spanName = $this->resolveSpanName($messageClass, 'send');

        $span = $this->getTracer()
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttribute('messaging.system', 'symfony_messenger')
            ->setAttribute('messaging.operation.type', 'send')
            ->setAttribute('messaging.operation.name', 'send')
            ->setAttribute('messaging.message.class', $messageClass)
            ->startSpan();

        $scope = $span->activate();

        try {
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

            $envelope = $stack->next()->handle($envelope, $stack);

            $destinations = [];
            foreach ($envelope->all(SentStamp::class) as $sentStamp) {
                /** @var SentStamp $sentStamp */
                $destination = $sentStamp->getSenderAlias() ?? $sentStamp->getSenderClass();
                if ('' !== $destination) {
                    $destinations[$destination] = true;
                }
            }

            // Per semconv, destination is only set when it applies to the whole operation.
            if (1 === \count($destinations)) {
                $span->setAttribute('messaging.destination.name', array_key_first($destinations));
            }

            /** @var TransportMessageIdStamp|null $idStamp */
            $idStamp = $envelope->last(TransportMessageIdStamp::class);
            $messageId = $idStamp?->getId();
            if (\is_scalar($messageId) && '' !== (string) $messageId) {
                $span->setAttribute('messaging.message.id', (string) $messageId);
            }

            return $envelope;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($e));
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * Consume side: create a span for the handled message.
     *
     * When rootSpans is false (default), the span is parented to the dispatching
     * trace via the stamp — standard distributed tracing behavior.
     *
     * When rootSpans is true, the span is created with no parent so task-oriented
     * backends (Traceway, Sentry) classify it as an independent job/task; the
     * producer context is preserved as a span link instead.
     */
    private function handleConsume(Envelope $envelope, StackInterface $stack): Envelope
    {
        $messageClass = $envelope->getMessage()::class;
        $spanName = $this->resolveSpanName($messageClass, 'process');

        $tracer = $this->getTracer();

        $parentContext = Context::getRoot();
        $linkContext = null;

        /** @var TraceContextStamp|null $stamp */
        $stamp = $envelope->last(TraceContextStamp::class);
        if (null !== $stamp) {
            $extracted = Globals::propagator()->extract($stamp->getHeaders());
            if ($this->rootSpans) {
                $linkContext = Span::fromContext($extracted)->getContext();
            } else {
                $parentContext = $extracted;
            }
        }

        $builder = $tracer->spanBuilder($spanName)
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.system', 'symfony_messenger')
            ->setAttribute('messaging.operation.type', 'process')
            ->setAttribute('messaging.operation.name', 'process')
            ->setAttribute('messaging.message.class', $messageClass);

        if (null !== $linkContext && $linkContext->isValid()) {
            $builder->addLink($linkContext);
        }

        /** @var ReceivedStamp|null $receivedStamp */
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (null !== $receivedStamp) {
            $builder->setAttribute('messaging.destination.name', $receivedStamp->getTransportName());
        }

        /** @var TransportMessageIdStamp|null $idStamp */
        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        $messageId = $idStamp?->getId();
        if (\is_scalar($messageId) && '' !== (string) $messageId) {
            $builder->setAttribute('messaging.message.id', (string) $messageId);
        }

        $span = $builder->startSpan();

        $scope = $span->activate();

        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($e));
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

    /**
     * Span name is "{operation} {ShortMessageClass}" — semconv operation-first
     * ordering, with the message class as the low-cardinality target so task
     * backends group per message type rather than per transport.
     *
     * @return non-empty-string
     */
    private function resolveSpanName(string $messageClass, string $operation): string
    {
        $pos = strrpos($messageClass, '\\');
        $shortName = false !== $pos ? substr($messageClass, $pos + 1) : $messageClass;

        return sprintf('%s %s', $operation, $shortName);
    }
}
