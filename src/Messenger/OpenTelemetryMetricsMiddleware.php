<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Messenger;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Emits OpenTelemetry metrics for Symfony Messenger consumer-side processing.
 *
 * Sibling of {@see OpenTelemetryMiddleware}. Lives on the same bus, wired via
 * the extension's prepend() when metrics.messenger.enabled. Dispatch side is
 * out of scope for this first PR; ConsumedByWorkerStamp or ReceivedStamp
 * presence triggers emission.
 *
 * Metrics (OTel messaging metrics semconv, Development status as of 2026-04):
 *   - messaging.process.duration             (Histogram, seconds)
 *   - messaging.client.consumed.messages     (Counter,  {message})
 *
 * Attributes (required + conditionally required per spec, trace-compatible):
 *   - messaging.system                  (required) -> "symfony_messenger"
 *   - messaging.operation.name          (required) -> "process"
 *   - messaging.operation.type          (trace-correlation) -> "process"
 *   - messaging.destination.name        (conditional) -> ReceivedStamp::getTransportName()
 *   - error.type                        (conditional, on failure) -> short exception class
 *
 * excluded_queues matches on the transport name (consume side only), same
 * field the trace middleware uses for messaging.destination.name.
 */
final class OpenTelemetryMetricsMiddleware implements MiddlewareInterface, ResetInterface
{
    private ?MeterInterface $meter = null;
    private ?HistogramInterface $duration = null;
    private ?CounterInterface $messages = null;

    /** @var list<string> */
    private readonly array $excludedQueues;

    /**
     * @param string[] $excludedQueues Receiver names to skip (consume side only)
     */
    public function __construct(
        private readonly string $meterName = 'opentelemetry-symfony',
        array $excludedQueues = [],
    ) {
        $this->excludedQueues = array_values($excludedQueues);
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->isConsuming($envelope)) {
            return $stack->next()->handle($envelope, $stack);
        }

        /** @var ReceivedStamp|null $receivedStamp */
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $destination = null !== $receivedStamp ? $receivedStamp->getTransportName() : null;

        if (null !== $destination && $this->isExcluded($destination)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $start = hrtime(true);

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
            $this->record($destination, $start, null);

            return $envelope;
        } catch (\Throwable $e) {
            $this->record($destination, $start, $e);

            throw $e;
        }
    }

    public function reset(): void
    {
        $this->meter = null;
        $this->duration = null;
        $this->messages = null;
    }

    private function record(?string $destination, int|float $start, ?\Throwable $exception): void
    {
        $attributes = $this->baseAttributes();
        if (null !== $destination) {
            $attributes['messaging.destination.name'] = $destination;
        }
        if (null !== $exception) {
            $attributes['error.type'] = (new \ReflectionClass($exception))->getShortName();
        }

        $this->getMessagesCounter()->add(1, $attributes);

        $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
        $this->getDurationHistogram()->record($durationSeconds, $attributes);
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function baseAttributes(): array
    {
        return [
            'messaging.system' => 'symfony_messenger',
            'messaging.operation.name' => 'process',
            'messaging.operation.type' => 'process',
        ];
    }

    private function isConsuming(Envelope $envelope): bool
    {
        return null !== $envelope->last(ReceivedStamp::class)
            || null !== $envelope->last(ConsumedByWorkerStamp::class);
    }

    private function isExcluded(string $destination): bool
    {
        return \in_array($destination, $this->excludedQueues, true);
    }

    private function getMeter(): MeterInterface
    {
        return $this->meter ??= Globals::meterProvider()->getMeter($this->meterName);
    }

    private function getDurationHistogram(): HistogramInterface
    {
        return $this->duration ??= $this->getMeter()->createHistogram(
            $this->metricName('duration'),
            's',
            'Duration of messaging processing operations',
        );
    }

    private function getMessagesCounter(): CounterInterface
    {
        return $this->messages ??= $this->getMeter()->createCounter(
            $this->metricName('messages'),
            '{message}',
            'Number of messages processed by the consumer',
        );
    }

    /**
     * Central metric name resolution. Ready for OTEL_SEMCONV_STABILITY_OPT_IN
     * dual-emit once messaging metrics semconv stabilizes.
     *
     * @param 'duration'|'messages' $key
     */
    private function metricName(string $key): string
    {
        return match ($key) {
            'duration' => 'messaging.process.duration',
            'messages' => 'messaging.client.consumed.messages',
        };
    }
}
