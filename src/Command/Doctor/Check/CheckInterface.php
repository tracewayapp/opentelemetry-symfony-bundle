<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check;

use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

interface CheckInterface
{
    /** @return non-empty-string */
    public function name(): string;

    /** @return non-empty-string */
    public function label(): string;

    public function group(): CheckGroup;

    public function run(CheckContext $context): CheckResult;
}
