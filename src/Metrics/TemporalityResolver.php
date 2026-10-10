<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Metrics;

/**
 * Turns metrics.temporality into the value for OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE.
 *
 * `auto` picks delta wherever PHP rebuilds the SDK for every request: each
 * request then holds its own short-lived cumulative totals, which a backend
 * cannot sum into one series, while delta measurements add up by definition.
 * Long-lived runtimes keep the SDK default.
 */
final class TemporalityResolver
{
    public const AUTO = 'auto';

    /** SAPIs whose SDK state does not outlive the request. */
    public const PER_REQUEST_SAPIS = ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli-server'];

    public static function resolve(?string $configured, string $sapi, bool $frankenPhpWorker): ?string
    {
        if (null === $configured) {
            return null;
        }

        if (self::AUTO !== $configured) {
            return $configured;
        }

        return self::isPerRequestRuntime($sapi, $frankenPhpWorker) ? 'delta' : null;
    }

    public static function isPerRequestRuntime(string $sapi, bool $frankenPhpWorker): bool
    {
        if ('frankenphp' === $sapi) {
            return !$frankenPhpWorker;
        }

        return \in_array($sapi, self::PER_REQUEST_SAPIS, true);
    }
}
