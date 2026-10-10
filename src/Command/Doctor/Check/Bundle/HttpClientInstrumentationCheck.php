<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

/**
 * Lists the outgoing HTTP client services the bundle actually instrumented in
 * this container, as recorded by the compiler passes, so an upgrade that
 * starts tracing Guzzle or PSR-18 clients is visible rather than inferred.
 */
final class HttpClientInstrumentationCheck implements CheckInterface
{
    public const PARAMETER_PREFIX = 'open_telemetry.http_client.instrumented.';

    private const FAMILIES = [
        'symfony' => 'Symfony HttpClient',
        'psr18' => 'PSR-18',
        'guzzle' => 'Guzzle',
    ];

    public function name(): string
    {
        return 'http_client_instrumentation';
    }

    public function label(): string
    {
        return 'Outgoing HTTP client services instrumented';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Bundle;
    }

    public function run(CheckContext $context): CheckResult
    {
        $families = [];
        $details = [];
        foreach (self::FAMILIES as $key => $label) {
            $ids = $context->param(self::PARAMETER_PREFIX.$key);
            if (!\is_array($ids)) {
                continue;
            }

            $ids = array_values(array_filter($ids, 'is_string'));
            $details[$key] = $ids;
            $families[] = \sprintf('%s: %s', $label, [] === $ids ? 'none' : implode(', ', $ids));
        }

        if ([] === $families) {
            return CheckResult::skipped($this->name(), 'traces.http_client is disabled');
        }

        $excluded = $context->param('open_telemetry.http_client.excluded_services', []);
        if (\is_array($excluded) && [] !== $excluded) {
            $families[] = 'excluded: '.implode(', ', array_filter($excluded, 'is_string'));
        }

        return CheckResult::info($this->name(), implode('; ', $families), $details);
    }
}
