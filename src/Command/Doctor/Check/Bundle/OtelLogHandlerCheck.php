<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle;

use OpenTelemetry\API\Logs\NoopLoggerProvider;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class OtelLogHandlerCheck implements CheckInterface
{
    private const MONOLOG_BUNDLE_CLASS = '\\Symfony\\Bundle\\MonologBundle\\MonologBundle';

    public function name(): string
    {
        return 'otel_log_handler';
    }

    public function label(): string
    {
        return 'OTel log handler is wired when log export is enabled';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Bundle;
    }

    public function run(CheckContext $context): CheckResult
    {
        $enabled = (bool) $context->param('open_telemetry.logs.export.enabled', false);
        if (!$enabled) {
            return CheckResult::skipped(
                $this->name(),
                'logs.export.enabled is false',
            );
        }

        if (!class_exists(self::MONOLOG_BUNDLE_CLASS)) {
            return CheckResult::error(
                $this->name(),
                'logs.export.enabled is true but MonologBundle is not installed',
                'Run: composer require symfony/monolog-bundle',
            );
        }

        $provider = $context->globals->loggerProvider();
        if ($provider instanceof NoopLoggerProvider) {
            return CheckResult::warning(
                $this->name(),
                'logs.export.enabled is true but Globals::loggerProvider() returns Noop — logs are dropped',
                'Set OTEL_PHP_AUTOLOAD_ENABLED=true and OTEL_LOGS_EXPORTER=otlp. Without these, the handler is wired into Monolog but the LoggerProvider has no exporter.',
                ['provider_class' => $provider::class],
            );
        }

        return CheckResult::ok(
            $this->name(),
            \sprintf('Log export wired (LoggerProvider: %s)', $this->shortClass($provider::class)),
            ['provider_class' => $provider::class],
        );
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
    }
}
