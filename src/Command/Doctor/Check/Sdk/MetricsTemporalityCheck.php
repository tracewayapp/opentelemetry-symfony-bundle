<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

/**
 * Cumulative temporality assumes one long-lived producer per time series. PHP
 * offers the opposite: FPM gives a process per request, and a worker runtime
 * gives several independent ones side by side, each with its own totals.
 *
 * Without `service.instance.id` those producers share a series identity and
 * overwrite each other's totals — silently, since nothing fails and the numbers
 * merely stop meaning anything. Which remedy applies depends on the runtime,
 * and the runtime cannot be read from a console command, so both are offered.
 */
final class MetricsTemporalityCheck implements CheckInterface
{
    private const DELTA = 'delta';

    /**
     * The third value the specification defines. The PHP exporter maps it to a
     * null preference, which defers to each instrument: synchronous ones report
     * delta, asynchronous ones cumulative.
     */
    private const LOW_MEMORY = 'lowmemory';

    private const SERVICE_INSTANCE_DETECTOR = 'service_instance';

    private const SERVICE_INSTANCE_ATTRIBUTE = 'service.instance.id';

    public function name(): string
    {
        return 'metrics_temporality';
    }

    public function label(): string
    {
        return 'Metric temporality matches the producer model';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Sdk;
    }

    public function run(CheckContext $context): CheckResult
    {
        if (true !== $context->param('open_telemetry.metrics.enabled')) {
            return CheckResult::skipped($this->name(), 'metrics are disabled');
        }

        $temporality = strtolower($context->env->get('OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') ?? 'cumulative');

        if (self::DELTA === $temporality) {
            return CheckResult::ok(
                $this->name(),
                'delta temporality: exports carry only what happened since the last one',
                ['temporality' => self::DELTA],
            );
        }

        $detectors = $context->env->get('OTEL_PHP_DETECTORS');
        $identitySource = $this->serviceInstanceSource($context, $detectors);

        if (self::LOW_MEMORY === $temporality) {
            return CheckResult::ok(
                $this->name(),
                'lowmemory temporality: counters and histograms export delta, observable instruments cumulative',
                ['temporality' => self::LOW_MEMORY, 'service_instance_id' => $identitySource ?? false],
            );
        }

        if (null !== $identitySource) {
            return CheckResult::ok(
                $this->name(),
                'cumulative temporality with a per-process service.instance.id',
                ['temporality' => $temporality, 'source' => $identitySource],
            );
        }

        return CheckResult::warning(
            $this->name(),
            'cumulative temporality without service.instance.id: producers share one time series',
            'Every process keeps its own cumulative totals, and without service.instance.id they are written to the same series, where they overwrite one another. '
            .'Under a worker runtime, give each worker an identity: OTEL_PHP_DETECTORS=host,process,service_instance (or set service.instance.id in OTEL_RESOURCE_ATTRIBUTES). '
            .'Under PHP-FPM that would instead create a series per request, so prefer OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta, where summing across producers is the intended semantics — your backend has to accept delta or convert it.',
            ['temporality' => $temporality, 'detectors' => $detectors],
        );
    }

    /**
     * Where an instance identity comes from, if anywhere. The detector derives
     * one per process; OTEL_RESOURCE_ATTRIBUTES states it outright, which is how
     * an orchestrator injects a pod or task name — and how this bundle passes on
     * its own `sdk.resource_attributes`.
     */
    private function serviceInstanceSource(CheckContext $context, ?string $detectors): ?string
    {
        if ($this->hasServiceInstanceDetector($detectors)) {
            return 'OTEL_PHP_DETECTORS';
        }

        if ($this->hasServiceInstanceAttribute($context->env->get('OTEL_RESOURCE_ATTRIBUTES'))) {
            return 'OTEL_RESOURCE_ATTRIBUTES';
        }

        return null;
    }

    private function hasServiceInstanceDetector(?string $detectors): bool
    {
        if (null === $detectors) {
            return false;
        }

        foreach (explode(',', $detectors) as $detector) {
            if (self::SERVICE_INSTANCE_DETECTOR === trim($detector)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A key with no value carries no identity, so an empty one does not count.
     */
    private function hasServiceInstanceAttribute(?string $attributes): bool
    {
        if (null === $attributes) {
            return false;
        }

        foreach (explode(',', $attributes) as $attribute) {
            $pair = explode('=', $attribute, 2);

            if (self::SERVICE_INSTANCE_ATTRIBUTE === trim($pair[0]) && '' !== trim($pair[1] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
