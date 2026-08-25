<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle;

use Composer\InstalledVersions;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\CacheTracingPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientMetricsPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Traceway\OpenTelemetryBundle\DependencyInjection\Compiler\MessengerMiddlewarePass;

final class OpenTelemetryBundle extends Bundle
{
    /**
     * Composer package name. Used to look up the installed version at runtime
     * for the OTel instrumentation scope's version field (a SHOULD-level
     * recommendation in the OTel spec).
     */
    public const PACKAGE_NAME = 'traceway/opentelemetry-symfony';

    /**
     * Telemetry Schema URL the bundle's emitted signals conform to. Stamped
     * on every Tracer/Meter/Logger instrumentation scope alongside the
     * library name and version.
     *
     * @see https://opentelemetry.io/docs/specs/otel/common/instrumentation-scope/
     */
    public const SCHEMA_URL = TraceAttributes::SCHEMA_URL;

    /** Not exposed by the SDK's Variables class; read by OpenTelemetry\Context\Context to skip DebugScope. */
    private const OTEL_PHP_DEBUG_SCOPES_DISABLED = 'OTEL_PHP_DEBUG_SCOPES_DISABLED';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * Returns the installed bundle version for the instrumentation scope.
     * Resolved at runtime via Composer's runtime API (cached in
     * `vendor/composer/installed.php`, no file I/O per call). Falls back to
     * the literal string "unknown" when the package isn't Composer-installed
     * (unusual — happens in some bundled phar / single-file deploy setups).
     */
    public static function version(): string
    {
        // getPrettyVersion() throws (not null) when the package isn't Composer-installed.
        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'unknown';
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientTracingPass());
        $container->addCompilerPass(new HttpClientMetricsPass());
        $container->addCompilerPass(new CacheTracingPass());
        $container->addCompilerPass(new MessengerMiddlewarePass(), priority: 10);
    }

    public function boot(): void
    {
        parent::boot();

        // Independent of sdk.enabled: the DebugScope cost applies to any app whose spans get activated.
        $this->disableDebugScopes($this->usePutEnv());
        $this->bootSdkConfig();
    }

    private function autoloadSdk(bool $autoloadEnabled, bool $usePutEnv): void
    {
        if (!$autoloadEnabled || Configuration::getBoolean(Variables::OTEL_PHP_AUTOLOAD_ENABLED)) {
            return;
        }

        $this->setEnvVariable(Variables::OTEL_PHP_AUTOLOAD_ENABLED, 'true', $usePutEnv);

        // require, not require_once: first include was a no-op because the env var was unset.
        require \sprintf('%1$s/_autoload.php', InstalledVersions::getInstallPath('open-telemetry/sdk'));
    }

    private function bootSdkConfig(): void
    {
        if (null === $this->container || !$this->container->hasParameter('open_telemetry.sdk.config')) {
            return;
        }

        /** @var array{enabled: bool, autoload_enabled: bool, use_putenv: bool, resource_attributes: array<string, string>, exporter_otlp_headers: array<string, string>} $sdkConfig */
        $sdkConfig = $this->container->getParameter('open_telemetry.sdk.config');
        $usePutEnv = $sdkConfig['use_putenv'];

        $this->autoloadSdk($sdkConfig['autoload_enabled'], $usePutEnv);

        $this->mergeEnvVariable(Variables::OTEL_RESOURCE_ATTRIBUTES, $sdkConfig['resource_attributes'], $usePutEnv);
        $this->mergeEnvVariable(Variables::OTEL_EXPORTER_OTLP_HEADERS, $sdkConfig['exporter_otlp_headers'], $usePutEnv);
    }

    private function usePutEnv(): bool
    {
        if (null === $this->container || !$this->container->hasParameter('open_telemetry.sdk.config')) {
            return false;
        }

        /** @var array{use_putenv: bool} $sdkConfig */
        $sdkConfig = $this->container->getParameter('open_telemetry.sdk.config');

        return $sdkConfig['use_putenv'];
    }

    // With zend.assertions=1, every Context::activate() builds a DebugScope, which captures a debug_backtrace().
    private function disableDebugScopes(bool $usePutEnv): void
    {
        if ('1' !== \ini_get('zend.assertions')) {
            return;
        }

        if (null !== $this->container && $this->container->hasParameter('kernel.debug') && true === $this->container->getParameter('kernel.debug')) {
            return;
        }

        if (isset($_SERVER[self::OTEL_PHP_DEBUG_SCOPES_DISABLED]) || false !== getenv(self::OTEL_PHP_DEBUG_SCOPES_DISABLED)) {
            return;
        }

        $this->setEnvVariable(self::OTEL_PHP_DEBUG_SCOPES_DISABLED, 'true', $usePutEnv);
    }

    /**
     * @param array<string, string> $values
     */
    private function mergeEnvVariable(string $name, array $values, bool $usePutEnv): void
    {
        if ([] === $values) {
            return;
        }

        /** @var array<string, string> $existing */
        $existing = Configuration::has($name) ? Configuration::getMap($name) : [];
        $combined = array_replace(array_map(rawurldecode(...), $existing), $values);

        $new = [];
        foreach ($combined as $key => $value) {
            // Percent-encode so values containing "," or "=" survive the W3C baggage format.
            $new[] = \sprintf('%1$s=%2$s', $key, rawurlencode($value));
        }

        $this->setEnvVariable($name, implode(',', $new), $usePutEnv);
    }

    private function setEnvVariable(string $name, string $value, bool $usePutEnv): void
    {
        $_SERVER[$name] = $_ENV[$name] = $value;

        if ($usePutEnv) {
            putenv(\sprintf('%1$s=%2$s', $name, $value));
        }
    }
}
