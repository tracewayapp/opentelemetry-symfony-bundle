<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Severity;
use Traceway\OpenTelemetryBundle\Command\Doctor\Output\JsonRenderer;
use Traceway\OpenTelemetryBundle\Command\Doctor\Output\TextRenderer;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckRunner;

#[AsCommand(
    name: 'traceway:doctor',
    description: 'Diagnose Traceway OpenTelemetry configuration and wiring',
    aliases: ['debug:traceway'],
)]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly CheckRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('skip-network', null, InputOption::VALUE_NONE, 'Skip checks that perform network I/O (OTLP reachability)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of check names to run (default: all)')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Severity threshold for exit code 1: info, warning, error', 'error')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Network check timeout in seconds', '1.0')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command runs diagnostic checks against the bundle's
runtime, SDK configuration, and OTLP endpoint reachability.

Examples:
  <comment>%command.full_name%</comment>
  <comment>%command.full_name% --format=json | jq .summary</comment>
  <comment>%command.full_name% --skip-network --fail-on=warning</comment>
  <comment>%command.full_name% --only=service_name,otlp_endpoint_configured</comment>

JSON output is stable (envelope: {version, summary, checks}) and safe for CI consumption.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatRaw = $input->getOption('format');
        $format = \is_string($formatRaw) ? $formatRaw : 'text';
        if (!\in_array($format, ['text', 'json'], true)) {
            $output->writeln(\sprintf('<error>Unknown --format "%s". Expected: text, json.</error>', $format));

            return Command::INVALID;
        }

        $failOnRaw = $input->getOption('fail-on');
        try {
            $failOn = Severity::fromName(\is_string($failOnRaw) ? $failOnRaw : 'error');
        } catch (\InvalidArgumentException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $onlyRaw = $input->getOption('only');
        $only = $this->parseOnlyOption(\is_string($onlyRaw) ? $onlyRaw : '');
        $skipNetwork = (bool) $input->getOption('skip-network');
        $timeoutRaw = $input->getOption('timeout');
        if (!is_numeric($timeoutRaw)) {
            $output->writeln(\sprintf('<error>Invalid --timeout "%s". Expected a positive number of seconds.</error>', \is_scalar($timeoutRaw) ? (string) $timeoutRaw : get_debug_type($timeoutRaw)));

            return Command::INVALID;
        }
        $timeout = (float) $timeoutRaw;
        if ($timeout <= 0.0) {
            $output->writeln('<error>--timeout must be a positive number of seconds.</error>');

            return Command::INVALID;
        }

        try {
            $report = $this->runner->run($skipNetwork, $only, $timeout);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $renderer = 'json' === $format
            ? new JsonRenderer($failOn)
            : new TextRenderer();
        $renderer->render($report, $output);

        return $report->hasFailureAtOrAbove($failOn) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function parseOnlyOption(string $raw): array
    {
        if ('' === $raw) {
            return [];
        }

        $names = array_map('trim', explode(',', $raw));

        return array_values(array_filter($names, static fn (string $n): bool => '' !== $n));
    }
}
