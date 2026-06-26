<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class OtlpEndpointConfiguredCheck implements CheckInterface
{
    public function name(): string
    {
        return 'otlp_endpoint_configured';
    }

    public function label(): string
    {
        return 'OTLP endpoint is configured';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Sdk;
    }

    public function run(CheckContext $context): CheckResult
    {
        $exporter = $context->env->get('OTEL_TRACES_EXPORTER') ?? 'otlp';
        if ('otlp' !== $exporter) {
            return CheckResult::skipped(
                $this->name(),
                \sprintf('OTEL_TRACES_EXPORTER is %s, not otlp', $exporter),
            );
        }

        $specific = $context->env->get('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
        $generic = $context->env->get('OTEL_EXPORTER_OTLP_ENDPOINT');

        if (null !== $specific) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT = %s', $specific),
                ['endpoint' => $specific, 'source' => 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'],
            );
        }

        if (null !== $generic) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('OTEL_EXPORTER_OTLP_ENDPOINT = %s', $generic),
                ['endpoint' => $generic, 'source' => 'OTEL_EXPORTER_OTLP_ENDPOINT'],
            );
        }

        return CheckResult::error(
            $this->name(),
            'OTLP exporter selected but no endpoint configured',
            'Set OTEL_EXPORTER_OTLP_ENDPOINT (e.g. http://localhost:4318 for http/protobuf, http://localhost:4317 for grpc) or the signal-specific OTEL_EXPORTER_OTLP_TRACES_ENDPOINT.',
        );
    }
}
