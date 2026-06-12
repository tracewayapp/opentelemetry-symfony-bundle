<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Mailer;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Traceway\OpenTelemetryBundle\Mailer\TraceableMailer;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableMailerTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testSendCreatesProducerSpan(): void
    {
        $mailer = new TraceableMailer($this->noopMailer(), 'test');

        $email = (new Email())
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Hello')
            ->text('Body');

        $mailer->send($email);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('create', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_PRODUCER, $spans[0]->getKind());
        self::assertSame(StatusCode::STATUS_UNSET, $spans[0]->getStatus()->getCode());

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('symfony_mailer', $attributes['messaging.system']);
        self::assertSame('create', $attributes['messaging.operation.name']);
        self::assertSame('create', $attributes['messaging.operation.type']);
        self::assertSame(1, $attributes['email.to.count']);
        self::assertArrayNotHasKey('email.subject', $attributes);
        self::assertArrayNotHasKey('messaging.destination.name', $attributes);
    }

    public function testRecipientCountSumsToCcBcc(): void
    {
        $mailer = new TraceableMailer($this->noopMailer(), 'test');

        $email = (new Email())
            ->from('sender@example.com')
            ->to('a@example.com', 'b@example.com')
            ->cc('c@example.com')
            ->bcc('d@example.com', 'e@example.com')
            ->subject('Hi')
            ->text('Body');

        $mailer->send($email);

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame(5, $attributes['email.to.count']);
    }

    public function testRecipientCountPrefersEnvelope(): void
    {
        $mailer = new TraceableMailer($this->noopMailer(), 'test');

        $email = (new Email())
            ->from('sender@example.com')
            ->to('header-only@example.com')
            ->subject('Hi')
            ->text('Body');

        $envelope = new Envelope(
            new Address('sender@example.com'),
            [new Address('actual@example.com'), new Address('bcc-recipient@example.com')],
        );

        $mailer->send($email, $envelope);

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame(2, $attributes['email.to.count']);
    }

    public function testSubjectRecordedWhenOptedIn(): void
    {
        $mailer = new TraceableMailer($this->noopMailer(), 'test', true);

        $email = (new Email())
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Password reset')
            ->text('Body');

        $mailer->send($email);

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('Password reset', $attributes['email.subject']);
    }

    public function testSubjectNotRecordedForRawMessage(): void
    {
        $mailer = new TraceableMailer($this->noopMailer(), 'test', true);

        $envelope = new Envelope(
            new Address('sender@example.com'),
            [new Address('alice@example.com')],
        );
        $mailer->send(new RawMessage('raw mime body'), $envelope);

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey('email.subject', $attributes);
        self::assertSame(1, $attributes['email.to.count']);
    }

    public function testXTransportHeaderBecomesDestinationName(): void
    {
        $mailer = new TraceableMailer($this->noopMailer(), 'test');

        $email = (new Email())
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Hi')
            ->text('Body');
        $email->getHeaders()->addTextHeader('X-Transport', 'alerts');

        $mailer->send($email);

        $span = $this->exporter->getSpans()[0];
        self::assertSame('create alerts', $span->getName());
        self::assertSame('alerts', $span->getAttributes()->toArray()['messaging.destination.name']);
    }

    public function testExceptionPathRecordsErrorAndRethrows(): void
    {
        $inner = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP down');
            }
        };

        $mailer = new TraceableMailer($inner, 'test');

        $email = (new Email())
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Hi')
            ->text('Body');

        try {
            $mailer->send($email);
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertSame('SMTP down', $e->getMessage());
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(TransportException::class, $span->getAttributes()->toArray()['error.type']);
        self::assertNotEmpty($span->getEvents());
    }

    private function noopMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
            }
        };
    }
}
