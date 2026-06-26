<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Severity;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\Status;

final class RunReport
{
    /** @param list<CompletedCheck> $checks */
    public function __construct(
        public readonly array $checks,
    ) {
    }

    /** @return array{ok: int, warning: int, error: int, skipped: int, info: int} */
    public function counts(): array
    {
        $counts = ['ok' => 0, 'warning' => 0, 'error' => 0, 'skipped' => 0, 'info' => 0];
        foreach ($this->checks as $check) {
            ++$counts[$check->result->status->value];
        }

        return $counts;
    }

    public function hasFailureAtOrAbove(Severity $threshold): bool
    {
        foreach ($this->checks as $check) {
            if (Status::Skipped === $check->result->status || Status::Info === $check->result->status) {
                continue;
            }
            if ($check->result->status->severity()->isAtLeast($threshold)) {
                return true;
            }
        }

        return false;
    }
}
