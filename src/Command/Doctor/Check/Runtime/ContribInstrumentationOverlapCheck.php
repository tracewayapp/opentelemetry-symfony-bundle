<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Check\Runtime;

use Composer\InstalledVersions;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Support\CheckContext;

/**
 * Warns when an opentelemetry-php-contrib auto-instrumentation covers what
 * this bundle already instruments, which yields every span or log twice.
 *
 * Contrib packages only run with ext-opentelemetry loaded, and each one can be
 * switched off by name through OTEL_PHP_DISABLED_INSTRUMENTATIONS.
 */
final class ContribInstrumentationOverlapCheck implements CheckInterface
{
    public const DISABLED_INSTRUMENTATIONS_ENV = 'OTEL_PHP_DISABLED_INSTRUMENTATIONS';

    /**
     * Composer package => [instrumentation name, what it duplicates, bundle parameters any of which makes it a duplicate].
     *
     * @var array<string, array{string, string, list<string>}>
     */
    public const OVERLAPS = [
        'open-telemetry/opentelemetry-auto-symfony' => ['symfony', 'HTTP server, HttpClient and Messenger spans', ['open_telemetry.traces.enabled']],
        'open-telemetry/opentelemetry-auto-guzzle' => ['guzzle', 'Guzzle CLIENT spans', ['open_telemetry.http_client.guzzle_enabled']],
        'open-telemetry/opentelemetry-auto-psr18' => ['psr18', 'PSR-18 CLIENT spans', ['open_telemetry.http_client.psr18_enabled']],
        'open-telemetry/opentelemetry-auto-http-async' => ['http-async-client', 'a CLIENT span above every Symfony HttpClient request made through HTTPlug', ['open_telemetry.http_client_enabled']],
        'open-telemetry/opentelemetry-auto-curl' => ['curl', 'a CLIENT span under every Symfony HttpClient or Guzzle request sent over curl', ['open_telemetry.http_client_enabled', 'open_telemetry.http_client.guzzle_enabled']],
        'open-telemetry/opentelemetry-auto-doctrine' => ['doctrine', 'Doctrine DBAL query spans', ['open_telemetry.traces.doctrine.enabled']],
        'open-telemetry/opentelemetry-auto-pdo' => ['pdo', 'a query span under every DBAL query on a pdo_* driver', ['open_telemetry.traces.doctrine.enabled']],
        'open-telemetry/opentelemetry-auto-mysqli' => ['mysqli', 'a query span under every DBAL query on the mysqli driver', ['open_telemetry.traces.doctrine.enabled']],
        'open-telemetry/opentelemetry-auto-postgresql' => ['postgresql', 'a query span under every DBAL query on the pgsql driver', ['open_telemetry.traces.doctrine.enabled']],
        'open-telemetry/opentelemetry-auto-psr6' => ['psr6', 'cache spans', ['open_telemetry.cache_enabled']],
        'open-telemetry/opentelemetry-auto-psr16' => ['psr16', 'cache spans', ['open_telemetry.cache_enabled']],
        'open-telemetry/opentelemetry-auto-psr3' => ['psr3', 'trace context injection into log records and, in export mode, every exported log record', ['open_telemetry.logs.correlation.enabled', 'open_telemetry.logs.export.enabled']],
    ];

    private readonly \Closure $isInstalled;
    private readonly \Closure $extensionLoaded;

    /**
     * @param (\Closure(string): bool)|null $isInstalled
     * @param (\Closure(): bool)|null       $extensionLoaded
     */
    public function __construct(?\Closure $isInstalled = null, ?\Closure $extensionLoaded = null)
    {
        $this->isInstalled = $isInstalled ?? static fn (string $package): bool => InstalledVersions::isInstalled($package);
        $this->extensionLoaded = $extensionLoaded ?? static fn (): bool => \extension_loaded('opentelemetry');
    }

    public function name(): string
    {
        return 'contrib_instrumentation_overlap';
    }

    public function label(): string
    {
        return 'Overlapping contrib auto-instrumentation';
    }

    public function group(): CheckGroup
    {
        return CheckGroup::Runtime;
    }

    public function run(CheckContext $context): CheckResult
    {
        if (true !== ($this->extensionLoaded)()) {
            return CheckResult::ok($this->name(), 'ext-opentelemetry not loaded, so no contrib auto-instrumentation can run');
        }

        $disabled = self::parseList($context->env->get(self::DISABLED_INSTRUMENTATIONS_ENV));
        if (\in_array('all', $disabled, true)) {
            return CheckResult::ok($this->name(), self::DISABLED_INSTRUMENTATIONS_ENV.'=all disables every contrib auto-instrumentation');
        }

        $overlaps = [];
        foreach (self::OVERLAPS as $package => [$instrumentation, $duplicates, $parameters]) {
            if (\in_array($instrumentation, $disabled, true) || true !== ($this->isInstalled)($package)) {
                continue;
            }

            foreach ($parameters as $parameter) {
                if (true === $context->param($parameter, false)) {
                    $overlaps[$instrumentation] = ['package' => $package, 'duplicates' => $duplicates];
                    break;
                }
            }
        }

        if ([] === $overlaps) {
            return CheckResult::ok($this->name(), 'No contrib auto-instrumentation duplicates this bundle');
        }

        $described = [];
        foreach ($overlaps as $overlap) {
            $described[] = \sprintf('%s (%s)', $overlap['package'], $overlap['duplicates']);
        }

        return CheckResult::warning(
            $this->name(),
            \sprintf('%d contrib auto-instrumentation(s) duplicate telemetry this bundle already records: %s', \count($overlaps), implode('; ', $described)),
            \sprintf(
                'Set %s=%s to let the bundle own these, or switch off the matching bundle option and keep the contrib package instead.',
                self::DISABLED_INSTRUMENTATIONS_ENV,
                implode(',', array_values(array_unique([...$disabled, ...array_keys($overlaps)]))),
            ),
            ['instrumentations' => array_keys($overlaps), 'packages' => array_column(array_values($overlaps), 'package'), 'disabled' => $disabled],
        );
    }

    /** @return list<string> */
    private static function parseList(?string $raw): array
    {
        if (null === $raw) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $raw)), static fn (string $v): bool => '' !== $v));
    }
}
