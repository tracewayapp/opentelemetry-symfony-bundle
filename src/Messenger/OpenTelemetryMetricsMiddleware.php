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
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Emits OpenTelemetry metrics for Symfony Messenger, on both the producer
 * (dispatch) and consumer (process) sides of the bus.
 *
 * Sibling of {@see OpenTelemetryMiddleware}. Lives on the same bus, wired via
 * the extension's prepend() when metrics.messenger.enabled. Dispatch is
 * detected by the absence of ReceivedStamp/ConsumedByWorkerStamp; consume by
 * their presence.
 *
 * Metrics (OTel messaging metrics semconv):
 *   Consume side:
 *     - messaging.process.duration             (Histogram, seconds)   [Development]
 *     - messaging.client.consumed.messages     (Counter,  {message})  [Development]
 *   Dispatch side:
 *     - messaging.client.operation.duration    (Histogram, seconds)   [Development]
 *     - messaging.client.sent.messages         (Counter,  {message})  [Development]
 *
 * Attributes:
 *   - messaging.system                  (required) -> "symfony_messenger"
 *   - messaging.operation.name          (required) -> "process" | "send"
 *   - messaging.operation.type          (trace-correlation) -> "process" | "send"
 *   - messaging.destination.name        (conditional)
 *       consume: ReceivedStamp::getTransportName()
 *       dispatch: SentStamp::getSenderAlias() ?? SentStamp::getSenderClass()
 *   - error.type                        (conditional, on failure) -> exception FQCN (parent class for anonymous)
 *
 * excluded_queues matches the transport name on both sides (one config,
 * symmetric semantics).
 */
final class OpenTelemetryMetricsMiddleware implements MiddlewareInterface, ResetInterface
{
    public const DURATION_BUCKET_BOUNDARIES = [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10,
    ];

    private ?MeterInterface $meter = null;
    private ?HistogramInterface $duration = null;
    private ?CounterInterface $messages = null;
    private ?HistogramInterface $dispatchDuration = null;
    private ?CounterInterface $dispatchSent = null;

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
        if ($this->isConsuming($envelope)) {
            return $this->handleConsume($envelope, $stack);
        }

        return $this->handleDispatch($envelope, $stack);
    }

    public function reset(): void
    {
        $this->meter = null;
        $this->duration = null;
        $this->messages = null;
        $this->dispatchDuration = null;
        $this->dispatchSent = null;
    }

    private function handleConsume(Envelope $envelope, StackInterface $stack): Envelope
    {
        /** @var ReceivedStamp|null $receivedStamp */
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $destination = null !== $receivedStamp ? $receivedStamp->getTransportName() : null;

        if (null !== $destination && $this->isExcluded($destination)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $start = hrtime(true);
        $exception = null;

        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            try {
                $this->record($destination, $start, $exception);
            } catch (\Throwable) {
            }
        }
    }

    private function handleDispatch(Envelope $envelope, StackInterface $stack): Envelope
    {
        $start = hrtime(true);
        $exception = null;

        try {
            $envelope = $stack->next()->handle($envelope, $stack);

            return $envelope;
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            try {
                $this->recordDispatch($envelope, $start, $exception);
            } catch (\Throwable) {
            }
        }
    }

    private function record(?string $destination, int|float $start, ?\Throwable $exception): void
    {
        $attributes = $this->baseAttributes('process');
        if (null !== $destination) {
            $attributes['messaging.destination.name'] = $destination;
        }
        if (null !== $exception) {
            $attributes['error.type'] = self::resolveErrorType($exception);
        }

        $this->getMessagesCounter()->add(1, $attributes);

        $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
        $this->getDurationHistogram()->record($durationSeconds, $attributes);
    }

    private function recordDispatch(Envelope $envelope, int|float $start, ?\Throwable $exception): void
    {
        $base = $this->baseAttributes('send');
        if (null !== $exception) {
            $base['error.type'] = self::resolveErrorType($exception);
        }

        $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $sentStamps = $envelope->all(SentStamp::class);

        if ([] === $sentStamps) {
            $this->getDispatchSentCounter()->add(1, $base);
            $this->getDispatchDurationHistogram()->record($durationSeconds, $base);

            return;
        }

        foreach ($sentStamps as $stamp) {
            /** @var SentStamp $stamp */
            $destination = $stamp->getSenderAlias() ?? $stamp->getSenderClass();

            if ($this->isExcluded($destination)) {
                continue;
            }

            $attributes = $base;
            $attributes['messaging.destination.name'] = $destination;

            $this->getDispatchSentCounter()->add(1, $attributes);
            $this->getDispatchDurationHistogram()->record($durationSeconds, $attributes);
        }
    }

    private static function resolveErrorType(\Throwable $exception): string
    {
        $type = $exception::class;

        if (str_contains($type, '@anonymous')) {
            $type = get_parent_class($exception) ?: \Throwable::class;
        }

        return $type;
    }

    /**
     * @param 'process'|'send' $operation
     *
     * @return array<non-empty-string, string>
     */
    private function baseAttributes(string $operation): array
    {
        return [
            'messaging.system' => 'symfony_messenger',
            'messaging.operation.name' => $operation,
            'messaging.operation.type' => $operation,
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
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKET_BOUNDARIES],
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

    private function getDispatchDurationHistogram(): HistogramInterface
    {
        return $this->dispatchDuration ??= $this->getMeter()->createHistogram(
            $this->metricName('dispatch_duration'),
            's',
            'Duration of messaging client send operations',
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKET_BOUNDARIES],
        );
    }

    private function getDispatchSentCounter(): CounterInterface
    {
        return $this->dispatchSent ??= $this->getMeter()->createCounter(
            $this->metricName('sent'),
            '{message}',
            'Number of messages sent to a transport',
        );
    }

    /**
     * Central metric name resolution. Ready for OTEL_SEMCONV_STABILITY_OPT_IN
     * dual-emit once messaging metrics semconv stabilizes.
     *
     * @param 'duration'|'messages'|'dispatch_duration'|'sent' $key
     */
    private function metricName(string $key): string
    {
        return match ($key) {
            'duration' => 'messaging.process.duration',
            'messages' => 'messaging.client.consumed.messages',
            'dispatch_duration' => 'messaging.client.operation.duration',
            'sent' => 'messaging.client.sent.messages',
        };
    }
}
