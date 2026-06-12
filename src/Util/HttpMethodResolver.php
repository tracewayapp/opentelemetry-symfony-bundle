<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

/**
 * Normalizes HTTP request methods per OTel semconv: unknown methods become _OTHER.
 */
final class HttpMethodResolver
{
    public const OTHER = '_OTHER';

    /** RFC 9110 methods plus PATCH (RFC 5789), overridable via OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS. */
    private const DEFAULT_KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH'];

    /** @var list<string>|null */
    private static ?array $knownMethods = null;

    /**
     * @return non-empty-string
     */
    public static function normalize(string $method): string
    {
        $upper = strtoupper($method);

        return \in_array($upper, self::knownMethods(), true) && '' !== $upper ? $upper : self::OTHER;
    }

    /**
     * The {method} portion of HTTP span names: the normalized method, or "HTTP" for _OTHER.
     *
     * @return non-empty-string
     */
    public static function spanNameMethod(string $method): string
    {
        $normalized = self::normalize($method);

        return self::OTHER === $normalized ? 'HTTP' : $normalized;
    }

    /**
     * @return list<string>
     */
    private static function knownMethods(): array
    {
        if (null !== self::$knownMethods) {
            return self::$knownMethods;
        }

        $env = $_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS']
            ?? $_ENV['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS']
            ?? getenv('OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS');

        if (\is_string($env) && '' !== trim($env)) {
            $methods = array_values(array_filter(array_map(
                static fn (string $m): string => strtoupper(trim($m)),
                explode(',', $env),
            ), static fn (string $m): bool => '' !== $m));

            if ([] !== $methods) {
                return self::$knownMethods = $methods;
            }
        }

        return self::$knownMethods = self::DEFAULT_KNOWN_METHODS;
    }
}
