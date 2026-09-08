<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Traceway\OpenTelemetryBundle\Metrics\MetricFlusherInterface;

/**
 * Drives metric collection from the application's own lifecycle.
 *
 * Sibling of {@see OtelLoggerFlushSubscriber}, and needed for a stronger
 * reason: a queued log record is eventually pushed by its batch processor,
 * whereas metrics have no trigger at all beyond provider shutdown.
 *
 * Three moments are covered, because request termination alone leaves a gap:
 *
 *  - kernel.terminate for HTTP, after the response has been sent;
 *  - console.terminate for commands;
 *  - WorkerRunningEvent for Messenger consumers, which run for hours and would
 *    otherwise export nothing until the worker stops. The event fires on every
 *    loop iteration, idle ones included, which suits an interval-limited flush.
 *
 * The interval lives in the flusher, so hooking up more entry points costs
 * nothing: whichever fires first pays for the export, the rest return early.
 */
final class OtelMetricsFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MetricFlusherInterface $flusher,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Last in line, as for the logger flush: everything that could still
        // record a measurement for this request has had its turn.
        $events = [
            KernelEvents::TERMINATE => ['flush', -1024],
        ];

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = ['flush', -1024];
        }

        if (class_exists(WorkerRunningEvent::class)) {
            $events[WorkerRunningEvent::class] = ['flush', -1024];
        }

        return $events;
    }

    /**
     * Takes no event on purpose: the three sources have no common type, and
     * none of them carries anything the flush needs.
     */
    public function flush(): void
    {
        $this->flusher->flush();
    }
}
