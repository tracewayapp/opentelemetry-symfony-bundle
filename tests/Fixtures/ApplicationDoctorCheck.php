<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Fixtures;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class ApplicationDoctorCheck implements CheckInterface
{
    public function name(): string
    {
        return 'application_check';
    }

    public function label(): string
    {
        return 'An application-defined check';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Bundle;
    }

    public function run(CheckContext $context): CheckResult
    {
        return CheckResult::ok($this->name(), 'fine');
    }
}
