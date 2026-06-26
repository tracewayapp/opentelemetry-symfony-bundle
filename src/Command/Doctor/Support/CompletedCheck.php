<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;

final class CompletedCheck
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly CheckGroup $group,
        public readonly CheckResult $result,
    ) {
    }
}
