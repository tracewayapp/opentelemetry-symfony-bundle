<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class TracesExporterCheck implements CheckInterface
{
    private const KNOWN_EXPORTERS = ['otlp', 'zipkin', 'console', 'memory', 'none'];

    public function name(): string
    {
        return 'traces_exporter';
    }

    public function label(): string
    {
        return 'OTEL_TRACES_EXPORTER is recognized';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Sdk;
    }

    public function run(CheckContext $context): CheckResult
    {
        $exporter = $context->env->get('OTEL_TRACES_EXPORTER') ?? 'otlp';

        if ('none' === $exporter) {
            return CheckResult::info(
                $this->name(),
                'OTEL_TRACES_EXPORTER = none (traces will be generated but not exported)',
                ['value' => 'none'],
            );
        }

        if (\in_array($exporter, self::KNOWN_EXPORTERS, true)) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('OTEL_TRACES_EXPORTER = %s', $exporter),
                ['value' => $exporter],
            );
        }

        return CheckResult::error(
            $this->name(),
            \sprintf('OTEL_TRACES_EXPORTER = "%s" is not recognized', $exporter),
            \sprintf('Expected one of: %s. Unrecognized values silently disable export.', implode(', ', self::KNOWN_EXPORTERS)),
            ['value' => $exporter],
        );
    }
}
