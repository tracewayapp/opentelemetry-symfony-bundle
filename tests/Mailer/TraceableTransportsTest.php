<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Mailer;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Traceway\OpenTelemetryBundle\Mailer\TraceableTransports;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class TraceableTransportsTest extends TestCase
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

    public function testSendCreatesClientSpan(): void
    {
        $sent = new SentMessage(
            $this->buildEmail(),
            new Envelope(new Address('sender@example.com'), [new Address('alice@example.com')]),
        );
        $sent->setMessageId('abc123@example.com');

        $transports = new TraceableTransports($this->fixedReturn($sent), 'test');
        $transports->send($this->buildEmail());

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('send', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());
        self::assertSame(StatusCode::STATUS_UNSET, $spans[0]->getStatus()->getCode());

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('symfony_mailer', $attributes['messaging.system']);
        self::assertSame('send', $attributes['messaging.operation.name']);
        self::assertSame('send', $attributes['messaging.operation.type']);
        self::assertSame('abc123@example.com', $attributes['messaging.message.id']);
    }

    public function testXTransportHeaderBecomesDestinationName(): void
    {
        $sent = new SentMessage(
            $this->buildEmail(),
            new Envelope(new Address('sender@example.com'), [new Address('alice@example.com')]),
        );
        $sent->setMessageId('msg-1');

        $transports = new TraceableTransports($this->fixedReturn($sent), 'test');

        $email = $this->buildEmail();
        $email->getHeaders()->addTextHeader('X-Transport', 'alerts');
        $transports->send($email);

        $span = $this->exporter->getSpans()[0];
        self::assertSame('send alerts', $span->getName());
        self::assertSame('alerts', $span->getAttributes()->toArray()['messaging.destination.name']);
    }

    public function testNullSentMessageOmitsMessageId(): void
    {
        $transports = new TraceableTransports($this->fixedReturn(null), 'test');
        $transports->send($this->buildEmail());

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey('messaging.message.id', $attributes);
    }

    public function testExceptionPathRecordsErrorAndRethrows(): void
    {
        $inner = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('connection refused');
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };

        $transports = new TraceableTransports($inner, 'test');

        try {
            $transports->send($this->buildEmail());
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertSame('connection refused', $e->getMessage());
        }

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(TransportException::class, $span->getAttributes()->toArray()['error.type']);
        self::assertNotEmpty($span->getEvents());
    }

    public function testToStringDelegates(): void
    {
        $inner = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return null;
            }

            public function __toString(): string
            {
                return 'smtp://relay.example.com:25';
            }
        };

        $transports = new TraceableTransports($inner, 'test');
        self::assertSame('smtp://relay.example.com:25', (string) $transports);
    }

    private function buildEmail(): Email
    {
        return (new Email())
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Hi')
            ->text('Body');
    }

    private function fixedReturn(?SentMessage $sent): TransportInterface
    {
        return new class($sent) implements TransportInterface {
            public function __construct(private readonly ?SentMessage $sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return $this->sent;
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };
    }
}
