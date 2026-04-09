<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Automatic console command instrumentation for Symfony using OpenTelemetry.
 *
 * Creates a SERVER span per command with the command name, exit code,
 * and exception recording. Built-in Symfony commands can be excluded.
 */
final class ConsoleSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

    /** @var \SplObjectStorage<Command, array{SpanInterface, ScopeInterface, bool}> */
    private \SplObjectStorage $commandSpans;

    /** @var array{SpanInterface, ScopeInterface, bool}|null Fallback for events with null command */
    private ?array $orphanSpan = null;

    /** @var string[] */
    private readonly array $excludedCommands;

    /**
     * @param string   $tracerName       Instrumentation library name
     * @param string[] $excludedCommands Command names to skip (e.g. cache:clear, assets:install)
     */
    public function __construct(
        private readonly string $tracerName = 'opentelemetry-symfony',
        array $excludedCommands = [],
    ) {
        $this->excludedCommands = array_values($excludedCommands);
        $this->commandSpans = new \SplObjectStorage();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 128],
            ConsoleEvents::ERROR => ['onError', 0],
            ConsoleEvents::TERMINATE => ['onTerminate', -128],
        ];
    }

    public function __destruct()
    {
        $this->drainSpans(suppressScopeNotice: true);
    }

    public function reset(): void
    {
        $this->tracer = null;
        $this->enabled = null;
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $command = $event->getCommand();
        $commandName = $this->resolveCommandName($command);

        if ($this->isExcluded($commandName)) {
            return;
        }

        $builder = $this->getTracer()
            ->spanBuilder($commandName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('process.command', $commandName);

        $args = (string) $event->getInput();
        if ('' !== $args) {
            $builder->setAttribute('process.command.args', $args);
        }

        $span = $builder->startSpan();
        $scope = $span->activate();

        $entry = [$span, $scope, false];

        if (null !== $command) {
            $this->commandSpans[$command] = $entry;
        } else {
            $this->orphanSpan = $entry;
        }
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $entry = $this->resolveEntry($event->getCommand());
        if (null === $entry) {
            return;
        }

        [$span, $scope] = $entry;

        if (!$span->isRecording()) {
            return;
        }

        $span->recordException($event->getError());
        $span->setStatus(StatusCode::STATUS_ERROR, $event->getError()->getMessage());

        $updated = [$span, $scope, true];
        $command = $event->getCommand();
        if (null !== $command && $this->commandSpans->contains($command)) {
            $this->commandSpans[$command] = $updated;
        } else {
            $this->orphanSpan = $updated;
        }
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();
        $entry = $this->resolveEntry($command);
        if (null === $entry) {
            return;
        }

        [$span, $scope, $errorRecorded] = $entry;

        $exitCode = $event->getExitCode();
        $span->setAttribute('process.exit_code', $exitCode);

        if ($exitCode !== Command::SUCCESS && !$errorRecorded && $span->isRecording()) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $span->end();
        $scope->detach();

        if (null !== $command && $this->commandSpans->contains($command)) {
            $this->commandSpans->detach($command);
        } else {
            $this->orphanSpan = null;
        }
    }

    /**
     * @return array{SpanInterface, ScopeInterface, bool}|null
     */
    private function resolveEntry(?Command $command): ?array
    {
        if (null !== $command && $this->commandSpans->contains($command)) {
            return $this->commandSpans[$command];
        }

        return $this->orphanSpan;
    }

    private function drainSpans(bool $suppressScopeNotice = false): void
    {
        foreach ($this->commandSpans as $command) {
            [$span, $scope] = $this->commandSpans[$command];
            $span->end();
            if ($suppressScopeNotice) {
                @$scope->detach();
            } else {
                $scope->detach();
            }
        }

        $this->commandSpans = new \SplObjectStorage();

        if (null !== $this->orphanSpan) {
            [$span, $scope] = $this->orphanSpan;
            $span->end();
            if ($suppressScopeNotice) {
                @$scope->detach();
            } else {
                $scope->detach();
            }
            $this->orphanSpan = null;
        }
    }

    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }

    private function isExcluded(string $commandName): bool
    {
        return \in_array($commandName, $this->excludedCommands, true);
    }

    /**
     * @return non-empty-string
     */
    private function resolveCommandName(?Command $command): string
    {
        if (null === $command) {
            return '<unknown>';
        }

        $name = $command->getName();

        return (null !== $name && '' !== $name) ? $name : $command::class;
    }
}
