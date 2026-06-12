<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventSubscriber;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Traceway\OpenTelemetryBundle\EventSubscriber\SchedulerSubscriber;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class SchedulerSubscriberTest extends TestCase
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

    public function testPreRunPostRunHappyPath(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        [$preRun, $postRun] = $this->buildEvents($message);

        $subscriber->onPreRun($preRun);
        $subscriber->onPostRun($postRun);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('process stdClass', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_CONSUMER, $spans[0]->getKind());
        self::assertSame(StatusCode::STATUS_UNSET, $spans[0]->getStatus()->getCode());

        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('symfony_scheduler', $attributes['messaging.system']);
        self::assertSame('process', $attributes['messaging.operation.name']);
        self::assertSame('process', $attributes['messaging.operation.type']);
        self::assertSame('msg-1', $attributes['messaging.message.id']);
        self::assertSame(\stdClass::class, $attributes['messaging.message.class']);
        self::assertSame('default', $attributes['messaging.destination.name']);
        self::assertSame('default', $attributes['scheduler.schedule.name']);
        self::assertSame('PeriodicalTrigger', $attributes['scheduler.trigger.type']);
        self::assertSame('every 300 seconds', $attributes['scheduler.trigger.expression']);
        self::assertSame('2026-05-12T19:00:00+00:00', $attributes['scheduler.triggered_at']);
        self::assertSame('2026-05-12T19:05:00+00:00', $attributes['scheduler.trigger.next_run_at']);
        self::assertArrayNotHasKey('messaging.destination.template', $attributes);
    }

    public function testNullNextTriggerAtOmitsAttribute(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $context = new MessageContext(
            name: 'default',
            id: 'msg-1',
            trigger: $this->buildTrigger(),
            triggeredAt: new \DateTimeImmutable('2026-05-12T19:00:00+00:00'),
            nextTriggerAt: null,
        );
        $preRun = new PreRunEvent($this->buildSchedule(), $context, $message);
        $postRun = new PostRunEvent($this->buildSchedule(), $context, $message);

        $subscriber->onPreRun($preRun);
        $subscriber->onPostRun($postRun);

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey('scheduler.trigger.next_run_at', $attributes);
    }

    public function testNoOpWhenTracerIsDisabled(): void
    {
        $this->tearDownOTel();

        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $preRun = $this->buildPreRunEvent($message);
        $postRun = new PostRunEvent($this->buildSchedule(), $this->buildContext(), $message);

        $subscriber->onPreRun($preRun);
        $subscriber->onPostRun($postRun);

        self::assertCount(0, $this->exporter->getSpans());
    }

    public function testFailurePathRecordsErrorAndExceptionEvent(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $preRun = $this->buildPreRunEvent($message);

        $subscriber->onPreRun($preRun);

        $error = new \RuntimeException('boom');
        $failure = new FailureEvent($this->buildSchedule(), $this->buildContext(), $message, $error);
        $subscriber->onFailure($failure);

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $span->getAttributes()->toArray()['error.type']);
        self::assertNotEmpty($span->getEvents());
    }

    public function testIgnoredFailureLeavesStatusUnset(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $preRun = $this->buildPreRunEvent($message);

        $subscriber->onPreRun($preRun);

        $failure = new FailureEvent($this->buildSchedule(), $this->buildContext(), $message, new \RuntimeException('transient'));
        $failure->shouldIgnore(true);
        $subscriber->onFailure($failure);

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $span->getAttributes()->toArray()['error.type']);
    }

    public function testCancellationEndsSpanWithMarker(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $preRun = $this->buildPreRunEvent($message);

        $subscriber->onPreRun($preRun);

        $preRun->shouldCancel(true);
        $subscriber->onPreRunLate($preRun);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertTrue($attributes['scheduler.cancelled']);
        self::assertSame(StatusCode::STATUS_UNSET, $spans[0]->getStatus()->getCode());
    }

    public function testNotCancelledPreRunLateIsNoOp(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $preRun = $this->buildPreRunEvent($message);

        $subscriber->onPreRun($preRun);
        $subscriber->onPreRunLate($preRun);

        // Span not yet ended (no Post/Failure) → exporter has no span yet.
        self::assertCount(0, $this->exporter->getSpans());
    }

    public function testPostRunWithoutPreRunIsNoOp(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $postRun = new PostRunEvent($this->buildSchedule(), $this->buildContext(), $message);

        $subscriber->onPostRun($postRun);

        self::assertCount(0, $this->exporter->getSpans());
    }

    public function testResetDrainsInFlightSpanAndClearsState(): void
    {
        $subscriber = new SchedulerSubscriber('test');
        $message = new \stdClass();
        $preRun = $this->buildPreRunEvent($message);

        $subscriber->onPreRun($preRun);
        $subscriber->reset();

        // Drain-on-reset ends the in-flight span and detaches its scope so a
        // long-running worker doesn't leak telemetry or the active context.
        self::assertCount(1, $this->exporter->getSpans());

        // Post-reset, storage is empty → onPostRun is a no-op.
        $postRun = new PostRunEvent($this->buildSchedule(), $this->buildContext(), $message);
        $subscriber->onPostRun($postRun);
        self::assertCount(1, $this->exporter->getSpans());
    }

    /**
     * @return array{0: PreRunEvent, 1: PostRunEvent}
     */
    private function buildEvents(object $message): array
    {
        return [$this->buildPreRunEvent($message), new PostRunEvent($this->buildSchedule(), $this->buildContext(), $message)];
    }

    private function buildPreRunEvent(object $message): PreRunEvent
    {
        return new PreRunEvent($this->buildSchedule(), $this->buildContext(), $message);
    }

    private function buildContext(): MessageContext
    {
        return new MessageContext(
            name: 'default',
            id: 'msg-1',
            trigger: $this->buildTrigger(),
            triggeredAt: new \DateTimeImmutable('2026-05-12T19:00:00+00:00'),
            nextTriggerAt: new \DateTimeImmutable('2026-05-12T19:05:00+00:00'),
        );
    }

    private function buildTrigger(): TriggerInterface
    {
        return new PeriodicalTrigger(300);
    }

    private function buildSchedule(): ScheduleProviderInterface
    {
        return new class implements ScheduleProviderInterface {
            public function getSchedule(): Schedule
            {
                return new Schedule();
            }
        };
    }
}
