<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime;

use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

final class DebugScopesCheck implements CheckInterface
{
    private const ENV_VAR = 'OTEL_PHP_DEBUG_SCOPES_DISABLED';

    /** @param string|null $assertionsOverride Test seam: null reads the live zend.assertions ini value. */
    public function __construct(private readonly ?string $assertionsOverride = null)
    {
    }

    public function name(): string
    {
        return 'debug_scopes';
    }

    public function label(): string
    {
        return 'Scope debugging overhead (zend.assertions)';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Runtime;
    }

    public function run(CheckContext $context): CheckResult
    {
        $assertions = $this->assertionsOverride ?? (string) \ini_get('zend.assertions');

        if ('1' !== $assertions) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('zend.assertions = %s; DebugScope is compiled out', '' === $assertions ? 'unknown' : $assertions),
                ['zend.assertions' => $assertions],
            );
        }

        $disabled = filter_var($context->env->get(self::ENV_VAR) ?? '', \FILTER_VALIDATE_BOOLEAN);

        if ($disabled) {
            return CheckResult::ok(
                $this->name(),
                \sprintf('zend.assertions = 1 but %s is set', self::ENV_VAR),
                ['zend.assertions' => '1', 'debug_scopes_disabled' => true],
            );
        }

        // In debug DebugScope is doing its job (it reports leaked scopes), and boot() leaves it alone for that reason.
        if ((bool) $context->param('kernel.debug', false)) {
            return CheckResult::ok(
                $this->name(),
                'zend.assertions = 1 in debug mode; DebugScope is expected here and reports leaked scopes',
                ['zend.assertions' => '1', 'debug_scopes_disabled' => false, 'kernel.debug' => true],
            );
        }

        return CheckResult::warning(
            $this->name(),
            'zend.assertions = 1, so every span activation captures a debug_backtrace() via OpenTelemetry\'s DebugScope',
            \sprintf('Set zend.assertions = 0 in php.ini (php.ini-production default) outside development, or set %s=1. The bundle sets it at boot when kernel.debug is false, so seeing this outside dev means boot has not run yet (this command runs before it in some setups) or the value was set explicitly.', self::ENV_VAR),
            ['zend.assertions' => '1', 'debug_scopes_disabled' => false, 'kernel.debug' => false],
        );
    }
}
