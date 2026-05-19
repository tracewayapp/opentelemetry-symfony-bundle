<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle;

use Composer\InstalledVersions;
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
}
