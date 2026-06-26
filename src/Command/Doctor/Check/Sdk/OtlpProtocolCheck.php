<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class OtlpProtocolCheck implements CheckInterface
{
    private const KNOWN_PROTOCOLS = ['http/json', 'http/protobuf', 'grpc'];

    public function name(): string
    {
        return 'otlp_protocol';
    }

    public function label(): string
    {
        return 'OTEL_EXPORTER_OTLP_PROTOCOL is valid';
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

        $protocol = $context->env->get('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL')
            ?? $context->env->get('OTEL_EXPORTER_OTLP_PROTOCOL');

        if (null === $protocol) {
            return CheckResult::ok(
                $this->name(),
                'OTEL_EXPORTER_OTLP_PROTOCOL unset (defaults to http/protobuf)',
                ['value' => null, 'effective' => 'http/protobuf'],
            );
        }

        if (\in_array($protocol, self::KNOWN_PROTOCOLS, true)) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('OTEL_EXPORTER_OTLP_PROTOCOL = %s', $protocol),
                ['value' => $protocol],
            );
        }

        return CheckResult::warning(
            $this->name(),
            \sprintf('OTEL_EXPORTER_OTLP_PROTOCOL = "%s" is not recognized', $protocol),
            \sprintf('Expected one of: %s. Unrecognized values fall back to the SDK default.', implode(', ', self::KNOWN_PROTOCOLS)),
            ['value' => $protocol],
        );
    }
}
