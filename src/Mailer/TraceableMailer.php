<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Mailer;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Decorates {@see MailerInterface} to emit a PRODUCER "create" span around send().
 *
 * Attribute shape follows OTel messaging semconv. Email-specific keys
 * (`email.subject`, `email.to.count`) anticipate semantic-conventions
 * issue open-telemetry/semantic-conventions#927 and align with the
 * ECS-derived keys used by Ruby contrib instrumentation.
 *
 * Subject is opt-in via {@see $recordSubject} (PII-adjacent). Recipient
 * count comes from {@see Envelope::getRecipients()} when supplied, falling
 * back to a sum of {@see Email::getTo()}+getCc()+getBcc() counts.
 *
 * The transport-level CLIENT span is added by {@see TraceableTransports}.
 * When the user has `framework.mailer.message_bus` set, the actual transport
 * send happens in a worker — this PRODUCER span only covers the dispatch,
 * which is the correct semantic.
 */
final class TraceableMailer implements MailerInterface, ResetInterface
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    public function __construct(
        private readonly MailerInterface $decorated,
        private readonly string $tracerName = 'opentelemetry-symfony',
        private readonly bool $recordSubject = false,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (!$this->isEnabled()) {
            $this->decorated->send($message, $envelope);

            return;
        }

        $transportName = $this->extractTransportName($message);
        $spanName = null !== $transportName ? \sprintf('create %s', $transportName) : 'create';

        $builder = $this->getTracer()
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttribute('messaging.system', 'symfony_mailer')
            ->setAttribute('messaging.operation.name', 'create')
            ->setAttribute('messaging.operation.type', 'create');

        if (null !== $transportName) {
            $builder->setAttribute('messaging.destination.name', $transportName);
        }

        $recipientCount = $this->countRecipients($message, $envelope);
        if (null !== $recipientCount) {
            $builder->setAttribute('email.to.count', $recipientCount);
        }

        if ($this->recordSubject && $message instanceof Email) {
            $subject = $message->getSubject();
            if (null !== $subject && '' !== $subject) {
                $builder->setAttribute('email.subject', $subject);
            }
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        try {
            $this->decorated->send($message, $envelope);
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

    public function reset(): void
    {
        $this->tracer = null;
        $this->enabled = null;
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

    private function countRecipients(RawMessage $message, ?Envelope $envelope): ?int
    {
        if (null !== $envelope) {
            return \count($envelope->getRecipients());
        }

        if ($message instanceof Email) {
            return \count($message->getTo()) + \count($message->getCc()) + \count($message->getBcc());
        }

        return null;
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
