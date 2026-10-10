<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Bundle;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class MessengerMiddlewareCheck implements CheckInterface
{
    private const MESSAGE_BUS_INTERFACE = '\\Symfony\\Component\\Messenger\\MessageBusInterface';

    public function name(): string
    {
        return 'messenger_middleware';
    }

    public function label(): string
    {
        return 'Messenger middleware is wired when enabled';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Bundle;
    }

    public function run(CheckContext $context): CheckResult
    {
        $enabled = (bool) $context->param('open_telemetry.traces.messenger.enabled', false);
        if (!$enabled) {
            return CheckResult::skipped(
                $this->name(),
                'traces.messenger.enabled is false',
            );
        }

        if (interface_exists(self::MESSAGE_BUS_INTERFACE)) {
            return CheckResult::ok(
                $this->name(),
                'Messenger tracing enabled and symfony/messenger is installed',
            );
        }

        // Messenger tracing is on by default, so this is the out-of-the-box state of
        // every application without Messenger, not a misconfiguration.
        return CheckResult::skipped(
            $this->name(),
            'symfony/messenger is not installed; Messenger tracing has nothing to instrument',
        );
    }
}
