<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Mailer;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Metrics\DurationBoundaries;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Decorates the `mailer.transports` service (the {@see TransportInterface}
 * aggregate) to emit OpenTelemetry metrics for outbound transport sends,
 * sibling of {@see TraceableTransports} on the trace side.
 *
 * Sits INSIDE the trace decorator: in Symfony service decoration, a higher
 * decoration priority nests further in (see
 * https://symfony.com/doc/current/service_container/service_decoration.html).
 * This decorator is registered at priority 8 while TraceableTransports is at 0,
 * so the call order is TraceableTransports → MeteredTransports → actual
 * mailer.transports. The metric is recorded in the finally block while the
 * trace span is still active, enabling SDK-level exemplar linkage from metric
 * data points back to traces.
 *
 * Metrics (OTel messaging metrics semconv):
 *   - messaging.client.operation.duration  (Histogram, s)         [Development]
 *   - messaging.client.sent.messages       (Counter,   {message}) [Development]
 *
 * Attributes:
 *   - messaging.system           -> "symfony_mailer"
 *   - messaging.operation.name   -> "send"
 *   - messaging.operation.type   -> "send"
 *   - messaging.destination.name (conditional) -> X-Transport header value
 *   - error.type                 (on failure)  -> exception FQCN (parent for anonymous)
 */
final class MeteredTransports implements TransportInterface, ResetInterface
{
    private ?MeterInterface $meter = null;
    private ?HistogramInterface $duration = null;
    private ?CounterInterface $sent = null;

    public function __construct(
        private readonly TransportInterface $decorated,
        private readonly string $meterName = 'opentelemetry-symfony',
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $attributes = $this->baseAttributes();
        $destination = $this->extractTransportName($message);
        if (null !== $destination) {
            $attributes['messaging.destination.name'] = $destination;
        }

        $start = hrtime(true);
        $exception = null;

        try {
            return $this->decorated->send($message, $envelope);
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            try {
                $this->record($start, $attributes, $exception);
            } catch (\Throwable) {
            }
        }
    }

    public function __toString(): string
    {
        return (string) $this->decorated;
    }

    public function reset(): void
    {
        $this->meter = null;
        $this->duration = null;
        $this->sent = null;
    }

    /**
     * @param array<non-empty-string, string> $attributes
     */
    private function record(int|float $start, array $attributes, ?\Throwable $exception): void
    {
        if (null !== $exception) {
            $attributes['error.type'] = ErrorTypeResolver::resolve($exception);
        }

        $this->getSentCounter()->add(1, $attributes);

        $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
        $this->getDurationHistogram()->record($durationSeconds, $attributes);
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function baseAttributes(): array
    {
        return [
            'messaging.system' => 'symfony_mailer',
            'messaging.operation.name' => 'send',
            'messaging.operation.type' => 'send',
        ];
    }

    private function extractTransportName(RawMessage $message): ?string
    {
        if (!$message instanceof Message) {
            return null;
        }

        $header = $message->getHeaders()->get('X-Transport');
        if (null === $header) {
            return null;
        }

        $value = $header->getBody();

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function getMeter(): MeterInterface
    {
        return $this->meter ??= Globals::meterProvider()->getMeter(
            $this->meterName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }

    private function getDurationHistogram(): HistogramInterface
    {
        // Description matches OpenTelemetryMetricsMiddleware's dispatch-side instrument
        // verbatim: both subsystems emit messaging.client.operation.duration, and the
        // OTel spec warns / drops metadata on duplicate (name, kind, unit) registrations
        // with conflicting descriptions.
        return $this->duration ??= $this->getMeter()->createHistogram(
            $this->metricName('duration'),
            's',
            'Duration of messaging client send operations',
            ['ExplicitBucketBoundaries' => DurationBoundaries::SECONDS],
        );
    }

    private function getSentCounter(): CounterInterface
    {
        // See description rationale on getDurationHistogram().
        return $this->sent ??= $this->getMeter()->createCounter(
            $this->metricName('sent'),
            '{message}',
            'Number of messages sent to a transport',
        );
    }

    /**
     * Central metric name resolution. Ready for OTEL_SEMCONV_STABILITY_OPT_IN
     * dual-emit once messaging metrics semconv stabilizes.
     *
     * @param 'duration'|'sent' $key
     */
    private function metricName(string $key): string
    {
        return match ($key) {
            'duration' => 'messaging.client.operation.duration',
            'sent' => 'messaging.client.sent.messages',
        };
    }
}
