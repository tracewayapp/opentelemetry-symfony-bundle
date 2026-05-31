<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Output;

use Symfony\Component\Console\Output\OutputInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Severity;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\RunReport;

final class JsonRenderer implements RendererInterface
{
    public function __construct(
        private readonly Severity $failOn = Severity::Error,
    ) {}

    public function render(RunReport $report, OutputInterface $output): void
    {
        $checks = [];
        foreach ($report->checks as $check) {
            $checks[] = [
                'name' => $check->name,
                'group' => $check->group->value,
                'status' => $check->result->status->value,
                'message' => $check->result->message,
                'remediation' => $check->result->remediation,
                'details' => (object) $check->result->details,
            ];
        }

        $counts = $report->counts();
        $payload = [
            'version' => 1,
            'summary' => [
                ...$counts,
                'exit_code' => $report->hasFailureAtOrAbove($this->failOn) ? 1 : 0,
            ],
            'checks' => $checks,
        ];

        $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
