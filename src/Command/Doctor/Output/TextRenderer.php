<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Output;

use Symfony\Component\Console\Output\OutputInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CompletedCheck;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\RunReport;

final class TextRenderer implements RendererInterface
{
    public function render(RunReport $report, OutputInterface $output): void
    {
        $output->writeln('<info>Traceway Doctor</info>');
        $output->writeln('<info>═══════════════</info>');
        $output->writeln('');

        $byGroup = [];
        foreach ($report->checks as $check) {
            $byGroup[$check->group->value][] = $check;
        }

        foreach (CheckGroup::cases() as $group) {
            if (empty($byGroup[$group->value])) {
                continue;
            }

            $output->writeln(\sprintf('<comment>%s</comment>', $group->label()));
            foreach ($byGroup[$group->value] as $check) {
                $this->renderCheck($check, $output);
            }
            $output->writeln('');
        }

        $counts = $report->counts();
        $output->writeln(\sprintf(
            'Results: <info>%d ok</info>, <comment>%d warning</comment>, <error>%d error</error>, %d skipped, %d info',
            $counts['ok'],
            $counts['warning'],
            $counts['error'],
            $counts['skipped'],
            $counts['info'],
        ));
    }

    private function renderCheck(CompletedCheck $check, OutputInterface $output): void
    {
        [$glyph, $tag] = match ($check->result->status) {
            Status::Ok => ['✓', 'info'],
            Status::Warning => ['⚠', 'comment'],
            Status::Error => ['✗', 'error'],
            Status::Skipped => ['○', 'comment'],
            Status::Info => ['ℹ', 'comment'],
        };

        $output->writeln(\sprintf(
            '  <%s>%s</%s> %s',
            $tag,
            $glyph,
            $tag,
            $this->escape($check->result->message),
        ));

        if (null !== $check->result->remediation) {
            $output->writeln(\sprintf(
                '    <comment>└ %s</comment>',
                $this->escape($check->result->remediation),
            ));
        }
    }

    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['\\<', '\\>'], $value);
    }
}
