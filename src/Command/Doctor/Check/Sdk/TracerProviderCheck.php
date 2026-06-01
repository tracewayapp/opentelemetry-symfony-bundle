<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class TracerProviderCheck implements CheckInterface
{
    public function name(): string
    {
        return 'tracer_provider';
    }

    public function label(): string
    {
        return 'TracerProvider is the SDK provider, not Noop';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Sdk;
    }

    public function run(CheckContext $context): CheckResult
    {
        $provider = $context->globals->tracerProvider();
        $class = $provider::class;
        $exporter = $context->env->get('OTEL_TRACES_EXPORTER') ?? 'otlp';

        if ($provider instanceof NoopTracerProvider) {
            if ('none' === $exporter) {
                return CheckResult::info(
                    $this->name(),
                    'TracerProvider is Noop (OTEL_TRACES_EXPORTER=none)',
                    ['class' => $class, 'exporter' => 'none'],
                );
            }

            return CheckResult::error(
                $this->name(),
                'TracerProvider is NoopTracerProvider — spans are silently dropped',
                'Set OTEL_PHP_AUTOLOAD_ENABLED=true and ensure open-telemetry/sdk is installed. Without these, the bundle generates spans but they go nowhere.',
                ['class' => $class],
            );
        }

        if ('none' === $exporter) {
            return CheckResult::info(
                $this->name(),
                sprintf('TracerProvider is %s, but OTEL_TRACES_EXPORTER=none', $this->shortClass($class)),
                ['class' => $class, 'exporter' => 'none'],
            );
        }

        return CheckResult::ok(
            $this->name(),
            sprintf('TracerProvider is %s', $this->shortClass($class)),
            ['class' => $class],
        );
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
    }
}
