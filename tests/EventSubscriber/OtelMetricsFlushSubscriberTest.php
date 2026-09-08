<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelMetricsFlushSubscriber;
use Traceway\OpenTelemetryBundle\Metrics\MetricFlusherInterface;

final class OtelMetricsFlushSubscriberTest extends TestCase
{
    public function testSubscribesToKernelAndConsoleTerminate(): void
    {
        $events = OtelMetricsFlushSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::TERMINATE, $events);
        self::assertArrayHasKey(ConsoleEvents::TERMINATE, $events);

        self::assertSame('flush', $events[KernelEvents::TERMINATE][0]);
        self::assertSame('flush', $events[ConsoleEvents::TERMINATE][0]);
    }

    public function testSubscribesToTheMessengerWorkerLoop(): void
    {
        // Without it a consumer running for hours would export nothing until
        // it stops, since console.terminate fires only once, at the very end.
        $events = OtelMetricsFlushSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(WorkerRunningEvent::class, $events);
        self::assertSame('flush', $events[WorkerRunningEvent::class][0]);
    }

    public function testRunsAfterEverythingElseThatCouldRecordAMeasurement(): void
    {
        foreach (OtelMetricsFlushSubscriber::getSubscribedEvents() as $listener) {
            self::assertSame(-1024, $listener[1]);
        }
    }

    public function testDelegatesToTheFlusher(): void
    {
        $flusher = new class implements MetricFlusherInterface {
            public int $calls = 0;

            public function flush(): bool
            {
                ++$this->calls;

                return true;
            }
        };

        (new OtelMetricsFlushSubscriber($flusher))->flush();

        self::assertSame(1, $flusher->calls);
    }
}
