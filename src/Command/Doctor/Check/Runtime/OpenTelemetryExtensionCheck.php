<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class OpenTelemetryExtensionCheck implements CheckInterface
{
    public function name(): string
    {
        return 'opentelemetry_extension_conflict';
    }

    public function label(): string
    {
        return 'ext-opentelemetry conflict check';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Runtime;
    }

    public function run(CheckContext $context): CheckResult
    {
        if (!\extension_loaded('opentelemetry')) {
            return CheckResult::ok(
                $this->name(),
                'ext-opentelemetry not loaded (no conflict risk)',
                ['loaded' => false],
            );
        }

        $version = phpversion('opentelemetry') ?: 'unknown';

        return CheckResult::warning(
            $this->name(),
            \sprintf('ext-opentelemetry %s is loaded alongside this bundle', $version),
            'If you also installed open-telemetry/opentelemetry-auto-symfony, you will see duplicate spans. Set OTEL_PHP_DISABLED_INSTRUMENTATIONS=symfony to disable the C-extension instrumentation, or remove this bundle if you prefer the C-extension path.',
            ['loaded' => true, 'version' => $version],
        );
    }
}
