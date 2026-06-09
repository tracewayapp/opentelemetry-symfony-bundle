<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class GrpcTransportCheck implements CheckInterface
{
    private const TRANSPORT_FACTORY_CLASS = '\\OpenTelemetry\\Contrib\\Grpc\\GrpcTransportFactory';

    public function name(): string
    {
        return 'grpc_transport';
    }

    public function label(): string
    {
        return 'gRPC OTLP transport is installed when configured';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Runtime;
    }

    public function run(CheckContext $context): CheckResult
    {
        $protocol = $context->env->get('OTEL_EXPORTER_OTLP_TRACES_PROTOCOL')
            ?? $context->env->get('OTEL_EXPORTER_OTLP_PROTOCOL')
            ?? 'http/protobuf';

        if ('grpc' !== $protocol) {
            return CheckResult::skipped(
                $this->name(),
                sprintf('protocol is %s; gRPC transport not required', $protocol),
            );
        }

        $extLoaded = \extension_loaded('grpc');
        $packageInstalled = class_exists(self::TRANSPORT_FACTORY_CLASS);

        $details = [
            'protocol' => $protocol,
            'ext_grpc_loaded' => $extLoaded,
            'transport_grpc_installed' => $packageInstalled,
        ];

        if ($extLoaded && $packageInstalled) {
            return CheckResult::ok(
                $this->name(),
                'gRPC OTLP transport is ready (ext-grpc loaded, open-telemetry/transport-grpc installed)',
                $details,
            );
        }

        $missing = [];
        if (!$extLoaded) {
            $missing[] = 'ext-grpc';
        }
        if (!$packageInstalled) {
            $missing[] = 'open-telemetry/transport-grpc';
        }

        return CheckResult::warning(
            $this->name(),
            sprintf('OTEL_EXPORTER_OTLP_PROTOCOL=grpc but %s missing', implode(' and ', $missing)),
            'gRPC export is not bundled. Install both pieces: `pecl install grpc` and `composer require open-telemetry/transport-grpc`. Without them, OTLP export will fail at runtime.',
            $details,
        );
    }
}
