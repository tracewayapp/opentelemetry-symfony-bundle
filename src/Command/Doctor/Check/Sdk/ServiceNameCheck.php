<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Sdk;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class ServiceNameCheck implements CheckInterface
{
    public function name(): string
    {
        return 'service_name';
    }

    public function label(): string
    {
        return 'OTEL_SERVICE_NAME is set';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Sdk;
    }

    public function run(CheckContext $context): CheckResult
    {
        $serviceName = $context->env->get('OTEL_SERVICE_NAME');
        if (null !== $serviceName) {
            return CheckResult::ok(
                $this->name(),
                sprintf('OTEL_SERVICE_NAME = "%s"', $serviceName),
                ['value' => $serviceName, 'source' => 'OTEL_SERVICE_NAME'],
            );
        }

        $resourceAttrs = $context->env->get('OTEL_RESOURCE_ATTRIBUTES') ?? '';
        if (str_contains($resourceAttrs, 'service.name=')) {
            return CheckResult::ok(
                $this->name(),
                'service.name set via OTEL_RESOURCE_ATTRIBUTES',
                ['source' => 'OTEL_RESOURCE_ATTRIBUTES'],
            );
        }

        return CheckResult::error(
            $this->name(),
            'OTEL_SERVICE_NAME is not set (traces will be attributed to "unknown_service")',
            'Set OTEL_SERVICE_NAME to your application name (e.g. OTEL_SERVICE_NAME=checkout-api) or include "service.name=..." in OTEL_RESOURCE_ATTRIBUTES.',
        );
    }
}
