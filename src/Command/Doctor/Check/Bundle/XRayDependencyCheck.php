<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class XRayDependencyCheck implements CheckInterface
{
    private const XRAY_PROPAGATOR_CLASS = '\\OpenTelemetry\\Contrib\\Aws\\Xray\\Propagator';

    public function name(): string
    {
        return 'xray_dependency';
    }

    public function label(): string
    {
        return 'AWS X-Ray dependency is installed when configured';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Bundle;
    }

    public function run(CheckContext $context): CheckResult
    {
        $propagatorRaw = $context->param('open_telemetry.traces.propagator', 'w3c');
        $idGeneratorRaw = $context->param('open_telemetry.traces.id_generator', 'default');
        $propagator = \is_string($propagatorRaw) ? $propagatorRaw : 'w3c';
        $idGenerator = \is_string($idGeneratorRaw) ? $idGeneratorRaw : 'default';

        $usesXRay = \in_array($propagator, ['xray', 'w3c+xray'], true) || 'xray' === $idGenerator;
        if (!$usesXRay) {
            return CheckResult::skipped(
                $this->name(),
                \sprintf('propagator=%s, id_generator=%s; X-Ray not configured', $propagator, $idGenerator),
            );
        }

        $details = ['propagator' => $propagator, 'id_generator' => $idGenerator];

        if (class_exists(self::XRAY_PROPAGATOR_CLASS)) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('AWS X-Ray configured (propagator=%s, id_generator=%s) and open-telemetry/contrib-aws is installed', $propagator, $idGenerator),
                $details,
            );
        }

        return CheckResult::error(
            $this->name(),
            \sprintf('AWS X-Ray configured (propagator=%s) but open-telemetry/contrib-aws is not installed', $propagator),
            'Run: composer require open-telemetry/contrib-aws',
            $details,
        );
    }
}
