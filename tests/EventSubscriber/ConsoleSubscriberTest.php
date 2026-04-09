<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventSubscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class ConsoleSubscriberTest extends TestCase
{
    use OTelTestTrait;

    private ConsoleSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->setUpOTel();
        $this->subscriber = new ConsoleSubscriber();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testSubscribedEvents(): void
    {
        $events = ConsoleSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ConsoleEvents::COMMAND, $events);
        self::assertArrayHasKey(ConsoleEvents::ERROR, $events);
        self::assertArrayHasKey(ConsoleEvents::TERMINATE, $events);
    }

    public function testCommandCreatesServerSpan(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('app:import', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_SERVER, $spans[0]->getKind());
    }

    public function testProcessCommandAttribute(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('app:import', $attributes['process.command']);
    }

    public function testExitCodeRecordedOnSpan(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame(0, $attributes['process.exit_code']);
    }

    public function testNonZeroExitCodeMarksError(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::FAILURE));

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(1, $span->getAttributes()->toArray()['process.exit_code']);
    }

    public function testExceptionRecordedOnSpan(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $exception = new \RuntimeException('Something broke');

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onError(new ConsoleErrorEvent($input, $output, $exception, $command));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::FAILURE));

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('Something broke', $span->getStatus()->getDescription());

        $events = $span->getEvents();
        self::assertNotEmpty($events);
        self::assertSame('exception', $events[0]->getName());
    }

    public function testExcludedCommandSkipsSpan(): void
    {
        $subscriber = new ConsoleSubscriber(excludedCommands: ['cache:clear']);
        $command = new Command('cache:clear');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testNonExcludedCommandStillTraced(): void
    {
        $subscriber = new ConsoleSubscriber(excludedCommands: ['cache:clear']);
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testSuccessfulCommandHasOkStatus(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    public function testOnErrorWithoutSpanIsNoop(): void
    {
        $subscriber = new ConsoleSubscriber(excludedCommands: ['cache:clear']);
        $command = new Command('cache:clear');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onError(new ConsoleErrorEvent($input, $output, new \RuntimeException('err'), $command));

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testOnTerminateWithoutSpanIsNoop(): void
    {
        $subscriber = new ConsoleSubscriber(excludedCommands: ['cache:clear']);
        $command = new Command('cache:clear');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        self::assertEmpty($this->exporter->getSpans());
    }

    public function testNullCommandFallsBackToUnknown(): void
    {
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent(null, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent(new Command('list'), $input, $output, Command::SUCCESS));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('<unknown>', $spans[0]->getName());
    }

    public function testCustomTracerName(): void
    {
        $subscriber = new ConsoleSubscriber(tracerName: 'my-custom-tracer');
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('app:import', $spans[0]->getName());
        self::assertSame('my-custom-tracer', $spans[0]->getInstrumentationScope()->getName());
    }

    public function testInvalidExitCodeMarksError(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 255));

        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(255, $span->getAttributes()->toArray()['process.exit_code']);
    }

    public function testDestructorEndsSpanWhenTerminateNeverFires(): void
    {
        $command = new Command('app:crash');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $subscriber = new ConsoleSubscriber();
        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));

        // Simulate the subscriber being garbage-collected without onTerminate
        unset($subscriber);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('app:crash', $spans[0]->getName());
    }

    public function testCommandArgsRecordedOnSpan(): void
    {
        $command = new Command('app:import');
        $command->addArgument('file', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        $input = new ArrayInput(['file' => '/tmp/data.csv']);
        $input->bind($command->getDefinition());
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertArrayHasKey('process.command.args', $attributes);
        self::assertStringContainsString('/tmp/data.csv', $attributes['process.command.args']);
    }

    public function testEmptyArgsNotRecorded(): void
    {
        $command = new Command('app:import');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $attributes = $this->exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey('process.command.args', $attributes);
    }

    public function testResetDoesNotDrainActiveSpans(): void
    {
        $command = new Command('app:long-running');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));

        // Simulate Symfony's services_resetter calling reset() between messages
        $this->subscriber->reset();

        // Span must NOT be exported yet — the command is still running
        self::assertEmpty($this->exporter->getSpans());

        // onTerminate should still work after reset
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('app:long-running', $spans[0]->getName());
    }

    /**
     * Proves the fix for issue #9: after reset(), the console span's scope
     * must stay active so that child spans (e.g. DBAL polling queries) attach
     * as children instead of becoming orphaned root spans.
     */
    public function testResetPreservesScopeForChildSpans(): void
    {
        $command = new Command('messenger:consume');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        // Exclude nothing — we want the console span active for this test
        $subscriber = new ConsoleSubscriber(excludedCommands: []);

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));

        // Simulate reset() called by services_resetter between messages
        $subscriber->reset();

        // Create a child span (simulates a DBAL polling query after reset)
        $tracer = Globals::tracerProvider()->getTracer('test');
        $childSpan = $tracer->spanBuilder('SELECT 1')->startSpan();
        $childSpan->end();

        // End the console span
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);

        // Child span (SELECT 1) was exported first (ended first)
        $child = $spans[0];
        // Parent span (messenger:consume) was exported second
        $parent = $spans[1];

        self::assertSame('SELECT 1', $child->getName());
        self::assertSame('messenger:consume', $parent->getName());

        // The child's parent must be the console span — NOT orphaned
        self::assertSame(
            $parent->getContext()->getSpanId(),
            $child->getParentSpanId(),
            'After reset(), child spans must still attach to the console span — not be orphaned root spans'
        );
    }

    public function testSpanCleanedUpAfterTerminate(): void
    {
        $command = new Command('app:first');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $this->subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS));

        $command2 = new Command('app:second');
        $this->subscriber->onCommand(new ConsoleCommandEvent($command2, $input, $output));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command2, $input, $output, Command::SUCCESS));

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('app:first', $spans[0]->getName());
        self::assertSame('app:second', $spans[1]->getName());
    }
}
