<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Messenger;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class OpenTelemetryMetricsMiddlewareTest extends TestCase
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

    public function testConsumeEmitsCounterAndDuration(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();

        self::assertArrayHasKey('messaging.client.consumed.messages', $metrics);
        $counter = $metrics['messaging.client.consumed.messages'];
        self::assertSame('{message}', $counter->unit);

        $points = [...$counter->data->dataPoints];
        self::assertCount(1, $points);
        self::assertSame(1, $points[0]->value);

        $attr = $points[0]->attributes->toArray();
        self::assertSame('symfony_messenger', $attr['messaging.system']);
        self::assertSame('process', $attr['messaging.operation.name']);
        self::assertSame('process', $attr['messaging.operation.type']);
        self::assertSame('async', $attr['messaging.destination.name']);
        self::assertArrayNotHasKey('error.type', $attr);

        self::assertArrayHasKey('messaging.process.duration', $metrics);
        $hist = $metrics['messaging.process.duration'];
        self::assertSame('s', $hist->unit);
        $histPoints = [...$hist->data->dataPoints];
        self::assertCount(1, $histPoints);
        self::assertSame(1, $histPoints[0]->count);
        self::assertGreaterThanOrEqual(0.0, $histPoints[0]->sum);
    }

    public function testConsumeFailureAddsErrorType(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $failing = new class implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
            {
                throw new \RuntimeException('boom');
            }
        };

        try {
            $middleware->handle($envelope, new StackMiddleware([$middleware, $failing]));
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.consumed.messages']->data->dataPoints];
        self::assertCount(1, $points);
        self::assertSame('RuntimeException', $points[0]->attributes->toArray()['error.type']);

        // duration is also recorded on failure
        self::assertArrayHasKey('messaging.process.duration', $metrics);
    }

    public function testDispatchWithoutSentStampsEmitsBaseMetric(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass());

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();

        self::assertArrayHasKey('messaging.client.sent.messages', $metrics);
        $points = [...$metrics['messaging.client.sent.messages']->data->dataPoints];
        self::assertCount(1, $points);
        self::assertSame(1, $points[0]->value);

        $attr = $points[0]->attributes->toArray();
        self::assertSame('symfony_messenger', $attr['messaging.system']);
        self::assertSame('send', $attr['messaging.operation.name']);
        self::assertSame('send', $attr['messaging.operation.type']);
        self::assertArrayNotHasKey('messaging.destination.name', $attr);

        self::assertArrayHasKey('messaging.client.operation.duration', $metrics);
        self::assertSame('s', $metrics['messaging.client.operation.duration']->unit);
    }

    public function testDispatchWithSingleSentStampSetsDestinationFromAlias(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new SentStamp(InMemoryTransport::class, 'async')]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.sent.messages']->data->dataPoints];
        self::assertCount(1, $points);
        self::assertSame('async', $points[0]->attributes->toArray()['messaging.destination.name']);
    }

    public function testDispatchWithSentStampMissingAliasFallsBackToSenderClass(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new SentStamp(InMemoryTransport::class, null)]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.sent.messages']->data->dataPoints];
        self::assertSame(InMemoryTransport::class, $points[0]->attributes->toArray()['messaging.destination.name']);
    }

    public function testDispatchWithMultipleSentStampsEmitsPerTransport(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [
            new SentStamp(InMemoryTransport::class, 'async'),
            new SentStamp(InMemoryTransport::class, 'audit'),
        ]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.sent.messages']->data->dataPoints];
        $destinations = array_map(
            static fn ($p): string => $p->attributes->toArray()['messaging.destination.name'],
            $points,
        );
        sort($destinations);
        self::assertSame(['async', 'audit'], $destinations);
    }

    public function testDispatchWithExcludedTransportSkipsThatStampOnly(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test', ['audit']);
        $envelope = new Envelope(new \stdClass(), [
            new SentStamp(InMemoryTransport::class, 'async'),
            new SentStamp(InMemoryTransport::class, 'audit'),
        ]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.sent.messages']->data->dataPoints];
        $destinations = array_map(
            static fn ($p): string => $p->attributes->toArray()['messaging.destination.name'],
            $points,
        );
        self::assertSame(['async'], $destinations);
    }

    public function testDispatchFailureAddsErrorType(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass());

        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \RuntimeException('transport down');
            }
        };

        try {
            $middleware->handle($envelope, new StackMiddleware([$middleware, $failing]));
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.sent.messages']->data->dataPoints];
        self::assertSame('RuntimeException', $points[0]->attributes->toArray()['error.type']);
    }

    public function testDispatchDurationHistogramUsesSecondBasedBuckets(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new SentStamp(InMemoryTransport::class, 'async')]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.operation.duration']->data->dataPoints];
        self::assertSame(
            OpenTelemetryMetricsMiddleware::DURATION_BUCKET_BOUNDARIES,
            $points[0]->explicitBounds,
        );
    }

    public function testDispatchRecordFailureDoesNotMaskTransportException(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $this->injectBrokenDispatchSentCounter($middleware);

        $envelope = new Envelope(new \stdClass());
        $businessException = new \DomainException('transport down');

        $failing = new class($businessException) implements MiddlewareInterface {
            public function __construct(private readonly \Throwable $e) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw $this->e;
            }
        };

        try {
            $middleware->handle($envelope, new StackMiddleware([$middleware, $failing]));
            self::fail('Expected DomainException');
        } catch (\DomainException $caught) {
            self::assertSame($businessException, $caught);
        }
    }

    public function testDispatchRecordFailureOnSuccessPathIsSwallowed(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $this->injectBrokenDispatchSentCounter($middleware);

        $envelope = new Envelope(new \stdClass());

        $result = $middleware->handle($envelope, new StackMiddleware());

        self::assertSame($envelope, $result);
    }

    private function injectBrokenDispatchSentCounter(OpenTelemetryMetricsMiddleware $middleware): void
    {
        $broken = $this->createMock(CounterInterface::class);
        $broken->method('add')->willThrowException(new \RuntimeException('metrics down'));

        $reflection = new \ReflectionClass($middleware);
        $property = $reflection->getProperty('dispatchSent');
        $property->setAccessible(true);
        $property->setValue($middleware, $broken);
    }

    public function testExcludedQueueEmitsNothing(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test', ['skip-me']);
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('skip-me')]);

        $middleware->handle($envelope, new StackMiddleware());

        self::assertSame([], $this->collectMetrics());
    }

    public function testConsumedByWorkerStampWithoutReceivedStampEmitsWithoutDestination(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ConsumedByWorkerStamp()]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.consumed.messages']->data->dataPoints];
        self::assertArrayNotHasKey('messaging.destination.name', $points[0]->attributes->toArray());
    }

    public function testResetClearsCachedMeter(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $middleware->handle($envelope, new StackMiddleware());
        $middleware->reset();
        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.consumed.messages']->data->dataPoints];
        self::assertCount(1, $points);
        self::assertSame(2, $points[0]->value);
    }

    public function testNamespacedExceptionUsesFqcn(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \Symfony\Component\Messenger\Exception\HandlerFailedException($envelope, [new \RuntimeException('boom')]);
            }
        };

        try {
            $middleware->handle($envelope, new StackMiddleware([$middleware, $failing]));
            self::fail('Expected HandlerFailedException');
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException) {
        }

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.consumed.messages']->data->dataPoints];
        self::assertSame(
            \Symfony\Component\Messenger\Exception\HandlerFailedException::class,
            $points[0]->attributes->toArray()['error.type'],
        );
    }

    public function testAnonymousExceptionFallsBackToParentClass(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new class('boom') extends \RuntimeException {};
            }
        };

        try {
            $middleware->handle($envelope, new StackMiddleware([$middleware, $failing]));
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.client.consumed.messages']->data->dataPoints];
        self::assertSame('RuntimeException', $points[0]->attributes->toArray()['error.type']);
    }

    public function testDurationHistogramUsesSecondBasedBuckets(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $middleware->handle($envelope, new StackMiddleware());

        $metrics = $this->collectMetrics();
        $points = [...$metrics['messaging.process.duration']->data->dataPoints];
        self::assertSame(
            OpenTelemetryMetricsMiddleware::DURATION_BUCKET_BOUNDARIES,
            $points[0]->explicitBounds,
        );
    }

    public function testRecordFailureDoesNotMaskHandlerException(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $this->injectBrokenCounter($middleware);

        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);
        $businessException = new \DomainException('handler failed');

        $failing = new class($businessException) implements MiddlewareInterface {
            public function __construct(private readonly \Throwable $e) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw $this->e;
            }
        };

        try {
            $middleware->handle($envelope, new StackMiddleware([$middleware, $failing]));
            self::fail('Expected DomainException');
        } catch (\DomainException $caught) {
            self::assertSame($businessException, $caught);
        }
    }

    public function testRecordFailureOnSuccessPathIsSwallowed(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware('test');
        $this->injectBrokenCounter($middleware);

        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('async')]);

        $result = $middleware->handle($envelope, new StackMiddleware());

        self::assertSame($envelope, $result);
    }

    private function injectBrokenCounter(OpenTelemetryMetricsMiddleware $middleware): void
    {
        $brokenCounter = $this->createMock(CounterInterface::class);
        $brokenCounter->method('add')->willThrowException(new \RuntimeException('metrics down'));

        $reflection = new \ReflectionClass($middleware);
        $messages = $reflection->getProperty('messages');
        $messages->setAccessible(true);
        $messages->setValue($middleware, $brokenCounter);
    }
}
