<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class TracesSamplerCheck implements CheckInterface
{
    private const KNOWN_SAMPLERS = [
        'always_on',
        'always_off',
        'traceidratio',
        'parentbased_always_on',
        'parentbased_always_off',
        'parentbased_traceidratio',
        'jaeger_remote',
        'xray',
    ];

    public function name(): string
    {
        return 'traces_sampler';
    }

    public function label(): string
    {
        return 'OTEL_TRACES_SAMPLER is recognized';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Sdk;
    }

    public function run(CheckContext $context): CheckResult
    {
        $sampler = $context->env->get('OTEL_TRACES_SAMPLER');
        $arg = $context->env->get('OTEL_TRACES_SAMPLER_ARG');

        if (null === $sampler) {
            return CheckResult::ok(
                $this->name(),
                'OTEL_TRACES_SAMPLER unset (defaults to parentbased_always_on)',
                ['value' => null],
            );
        }

        if ('always_off' === $sampler || 'parentbased_always_off' === $sampler) {
            return CheckResult::warning(
                $this->name(),
                \sprintf('OTEL_TRACES_SAMPLER = %s — no spans will be sampled', $sampler),
                'Use parentbased_traceidratio with OTEL_TRACES_SAMPLER_ARG=0.1 (or your desired ratio) instead of always_off if you want partial sampling.',
                ['value' => $sampler, 'arg' => $arg],
            );
        }

        if (\in_array($sampler, self::KNOWN_SAMPLERS, true)) {
            $message = null !== $arg
                ? \sprintf('OTEL_TRACES_SAMPLER = %s (arg: %s)', $sampler, $arg)
                : \sprintf('OTEL_TRACES_SAMPLER = %s', $sampler);

            return CheckResult::ok($this->name(), $message, ['value' => $sampler, 'arg' => $arg]);
        }

        return CheckResult::error(
            $this->name(),
            \sprintf('OTEL_TRACES_SAMPLER = "%s" is not recognized', $sampler),
            \sprintf('Expected one of: %s', implode(', ', self::KNOWN_SAMPLERS)),
            ['value' => $sampler, 'arg' => $arg],
        );
    }
}
