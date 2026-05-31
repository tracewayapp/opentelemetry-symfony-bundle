<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Output;

use Symfony\Component\Console\Output\OutputInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\RunReport;

interface RendererInterface
{
    public function render(RunReport $report, OutputInterface $output): void;
}
