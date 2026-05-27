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
        return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'unknown';
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientTracingPass());
        $container->addCompilerPass(new HttpClientMetricsPass());
        $container->addCompilerPass(new CacheTracingPass());
    }

    public function boot(): void
    {
        parent::boot();

        $this->bootSdkConfig();
    }

    private function bootSdkConfig(): void
    {
        if (!$this->container->hasParameter('open_telemetry.sdk.config')) {
            return;
        }

        $sdkConfig = $this->container->getParameter('open_telemetry.sdk.config');
        $usePutEnv = $sdkConfig['use_putenv'];

        if ($sdkConfig['autoload_enabled'] && !Configuration::getBoolean(Variables::OTEL_PHP_AUTOLOAD_ENABLED) && InstalledVersions::isInstalled('open-telemetry/sdk')) {
            $this->setEnvVariable(Variables::OTEL_PHP_AUTOLOAD_ENABLED, 'true', $usePutEnv);

            require sprintf('%1$s/_autoload.php', InstalledVersions::getInstallPath('open-telemetry/sdk'));
        }

        $this->mergeEnvVariable(Variables::OTEL_RESOURCE_ATTRIBUTES, $sdkConfig['resource_attributes'], $usePutEnv);
        $this->mergeEnvVariable(Variables::OTEL_EXPORTER_OTLP_HEADERS, $sdkConfig['exporter_otlp_headers'], $usePutEnv);
    }

    private function mergeEnvVariable(string $name, array $values, bool $usePutEnv): void
    {
        if ([] === $values) {
            return;
        }

        $existing = Configuration::has($name) ? (Configuration::getMap($name) ?? []) : [];
        $combined = array_replace($existing, $values);

        $new = [];
        foreach ($combined as $key => $value) {
            $new[] = sprintf('%1$s=%2$s', $key, $value);
        }

        $this->setEnvVariable($name, implode(',', $new), $usePutEnv);
    }

    private function setEnvVariable(string $name, string $value, bool $usePutEnv): void
    {
        $_SERVER[$name] = $_ENV[$name] = $value;

        if ($usePutEnv) {
            putenv(sprintf('%1$s=%2$s', $name, $value));
        }
    }
}
