<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Mailer;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Instrumentation\TracerAwareTrait;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Decorates the `mailer.transports` service (the {@see TransportInterface}
 * aggregate) to emit a CLIENT span around the transport send. Sibling of
 * {@see TraceableMailer}, which emits the higher-level PRODUCER span.
 *
 * X-Transport is read before delegation because {@see \Symfony\Component\Mailer\Transport\Transports}
 * removes the header before forwarding to the chosen transport.
 * {@see SentMessage::getMessageId()} is captured on success.
 *
 * Server address/port for SMTP transports would require per-transport
 * decoration via a compiler pass. Intentionally omitted — the value is
 * low-impact for the common single-transport case. Open an issue if your
 * setup needs it.
 */
final class TraceableTransports implements TransportInterface, ResetInterface
{
    use TracerAwareTrait;

    public function __construct(
        private readonly TransportInterface $decorated,
        private readonly string $tracerName = 'opentelemetry-symfony',
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (!$this->isEnabled()) {
            return $this->decorated->send($message, $envelope);
        }

        $transportName = TransportNameResolver::fromMessage($message);
        $spanName = null !== $transportName ? \sprintf('send %s', $transportName) : 'send';

        $builder = $this->getTracer()
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('messaging.system', 'symfony_mailer')
            ->setAttribute('messaging.operation.name', 'send')
            ->setAttribute('messaging.operation.type', 'send');

        if (null !== $transportName) {
            $builder->setAttribute('messaging.destination.name', $transportName);
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        try {
            $sent = $this->decorated->send($message, $envelope);
            if (null !== $sent && '' !== $sent->getMessageId()) {
                $span->setAttribute('messaging.message.id', $sent->getMessageId());
            }

            return $sent;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setAttribute('error.type', ErrorTypeResolver::resolve($e));
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function __toString(): string
    {
        return (string) $this->decorated;
    }

    public function reset(): void
    {
        $this->resetTracer();
    }
}
