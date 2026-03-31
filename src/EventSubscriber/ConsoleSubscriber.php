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

/**
 * Automatic console command instrumentation for Symfony using OpenTelemetry.
 *
 * Creates a SERVER span per command with the command name, exit code,
 * and exception recording. Built-in Symfony commands can be excluded.
 */
final class ConsoleSubscriber implements EventSubscriberInterface
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;
    private ?SpanInterface $span = null;
    private ?ScopeInterface $scope = null;
    private bool $errorRecorded = false;

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
        $this->endSpan(suppressScopeNotice: true);
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $commandName = $this->resolveCommandName($event->getCommand());

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
        $this->span = $span;
        $this->scope = $span->activate();
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        if (null === $this->span || !$this->span->isRecording()) {
            return;
        }

        $this->span->recordException($event->getError());
        $this->span->setStatus(StatusCode::STATUS_ERROR, $event->getError()->getMessage());
        $this->errorRecorded = true;
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if (null === $this->span) {
            return;
        }

        $exitCode = $event->getExitCode();
        $this->span->setAttribute('process.exit_code', $exitCode);

        if ($exitCode !== Command::SUCCESS && !$this->errorRecorded && $this->span->isRecording()) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->endSpan();
    }

    private function endSpan(bool $suppressScopeNotice = false): void
    {
        if ($this->scope !== null) {
            if ($suppressScopeNotice) {
                @$this->scope->detach();
            } else {
                $this->scope->detach();
            }
            $this->scope = null;
        }

        $this->span?->end();
        $this->span = null;
        $this->errorRecorded = false;
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
