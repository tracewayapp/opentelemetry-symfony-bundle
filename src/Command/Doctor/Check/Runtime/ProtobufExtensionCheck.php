<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class ProtobufExtensionCheck implements CheckInterface
{
    public function name(): string
    {
        return 'protobuf_extension';
    }

    public function label(): string
    {
        return 'ext-protobuf availability';
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

        $needsProtobuf = \in_array($protocol, ['http/protobuf', 'grpc'], true);
        $loaded = \extension_loaded('protobuf');

        if (!$needsProtobuf) {
            return CheckResult::skipped(
                $this->name(),
                \sprintf('protocol is %s; ext-protobuf not required', $protocol),
            );
        }

        if ($loaded) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('ext-protobuf loaded (protocol: %s)', $protocol),
                ['protocol' => $protocol, 'loaded' => true],
            );
        }

        return CheckResult::warning(
            $this->name(),
            \sprintf('ext-protobuf not loaded but protocol is %s', $protocol),
            'Install ext-protobuf for significantly faster OTLP encoding: pecl install protobuf. The pure-PHP fallback works but is slower for high-throughput services.',
            ['protocol' => $protocol, 'loaded' => false],
        );
    }
}
