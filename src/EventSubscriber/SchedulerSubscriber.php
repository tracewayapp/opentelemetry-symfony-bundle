<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Automatic Symfony Scheduler instrumentation.
 *
 * Listens to {@see PreRunEvent}, {@see PostRunEvent}, {@see FailureEvent} and
 * emits a CONSUMER span per scheduled task run, with OTel messaging semconv
 * attributes plus a Traceway-specific `scheduler.*` namespace for trigger
 * metadata (anticipating any future OTel scheduler semconv).
 *
 * Scheduler dispatches go through the Messenger bus, so
 * {@see \Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware} would
 * otherwise emit parallel generic PRODUCER and CONSUMER spans. That middleware
 * suppresses its own spans when the envelope carries a Symfony
 * {@see \Symfony\Component\Scheduler\Messenger\ScheduledStamp}, letting this
 * subscriber own the canonical span regardless of transport naming.
 *
 * Cancellation: {@see PreRunEvent::shouldCancel(true)} can be set by any
 * listener; the scheduler then skips the handler and neither Post nor
 * Failure fires. We register a low-priority PreRun listener that detects
 * this and ends the span with a `scheduler.cancelled` attribute so the
 * cancellation is observable.
 */
final class SchedulerSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    /** @var \SplObjectStorage<object, array{SpanInterface, ScopeInterface}> */
    private \SplObjectStorage $spans;

    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
    ) {
        $this->spans = new \SplObjectStorage();
    }

    /**
     * @return array<string, list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PreRunEvent::class => [
                ['onPreRun', 256],
                ['onPreRunLate', -256],
            ],
            PostRunEvent::class => [['onPostRun', 0]],
            FailureEvent::class => [['onFailure', 0]],
        ];
    }

    public function onPreRun(PreRunEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $message = $event->getMessage();
        $context = $event->getMessageContext();
        $messageClass = $message::class;

        // RecurringMessage reuses the same instance; close any stale entry before overwriting.
        if ($this->spans->offsetExists($message)) {
            [$staleSpan, $staleScope] = $this->spans[$message];
            $this->spans->offsetUnset($message);
            $staleSpan->end();
            @$staleScope->detach();
        }

        $builder = $this->getTracer()
            ->spanBuilder(\sprintf('process %s', $this->shortName($messageClass)))
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.system', 'symfony_scheduler')
            ->setAttribute('messaging.operation.name', 'process')
            ->setAttribute('messaging.operation.type', 'process')
            ->setAttribute('messaging.message.id', $context->id)
            ->setAttribute('messaging.message.class', $messageClass)
            ->setAttribute('messaging.destination.name', $context->name)
            ->setAttribute('scheduler.schedule.name', $context->name)
            ->setAttribute('scheduler.trigger.type', $this->shortName($context->trigger::class))
            ->setAttribute('scheduler.trigger.expression', (string) $context->trigger)
            ->setAttribute('scheduler.triggered_at', $context->triggeredAt->format(\DateTimeInterface::ATOM));

        if (null !== $context->nextTriggerAt) {
            $builder->setAttribute('scheduler.trigger.next_run_at', $context->nextTriggerAt->format(\DateTimeInterface::ATOM));
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        $this->spans[$message] = [$span, $scope];
    }

    public function onPreRunLate(PreRunEvent $event): void
    {
        if (!$event->shouldCancel()) {
            return;
        }

        $message = $event->getMessage();
        if (!$this->spans->offsetExists($message)) {
            return;
        }

        [$span, $scope] = $this->spans[$message];
        $this->spans->offsetUnset($message);

        $span->setAttribute('scheduler.cancelled', true);
        $scope->detach();
        $span->end();
    }

    public function onPostRun(PostRunEvent $event): void
    {
        $message = $event->getMessage();
        if (!$this->spans->offsetExists($message)) {
            return;
        }

        [$span, $scope] = $this->spans[$message];
        $this->spans->offsetUnset($message);

        $scope->detach();
        $span->end();
    }

    public function onFailure(FailureEvent $event): void
    {
        $message = $event->getMessage();
        if (!$this->spans->offsetExists($message)) {
            return;
        }

        [$span, $scope] = $this->spans[$message];
        $this->spans->offsetUnset($message);

        $error = $event->getError();
        $span->recordException($error);
        $span->setAttribute('error.type', ErrorTypeResolver::resolve($error));

        if (!$event->shouldIgnore()) {
            $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        }

        $scope->detach();
        $span->end();
    }

    public function __destruct()
    {
        $this->drainSpans(suppressScopeNotice: true);
    }

    public function reset(): void
    {
        $this->drainSpans();
        $this->tracer = null;
        $this->enabled = null;
    }

    private function drainSpans(bool $suppressScopeNotice = false): void
    {
        foreach ($this->spans as $message) {
            [$span, $scope] = $this->spans[$message];
            if ($suppressScopeNotice) {
                @$scope->detach();
            } else {
                $scope->detach();
            }
            $span->end();
        }
        $this->spans = new \SplObjectStorage();
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

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        $name = false !== $pos ? substr($fqcn, $pos + 1) : $fqcn;

        return '' !== $name ? $name : $fqcn;
    }
}
