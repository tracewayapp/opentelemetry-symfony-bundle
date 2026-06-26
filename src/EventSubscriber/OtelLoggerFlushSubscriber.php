<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface as SdkLoggerProviderInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the OTel LoggerProvider on request and command termination.
 *
 * Without this, log records queued inside a BatchLogRecordProcessor can be lost
 * when a short-lived request or command exits before the processor's scheduled
 * flush (default 1s) fires.
 */
final class OtelLoggerFlushSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::TERMINATE => ['flush', -1024],
        ];

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = ['flush', -1024];
        }

        return $events;
    }

    public function flush(TerminateEvent|ConsoleTerminateEvent $event): void
    {
        try {
            $provider = Globals::loggerProvider();
            if ($provider instanceof SdkLoggerProviderInterface) {
                $provider->forceFlush();
            }
        } catch (\Throwable $e) {
            error_log(\sprintf('OtelLoggerFlushSubscriber: forceFlush failed: %s', $e->getMessage()));
        }
    }
}
