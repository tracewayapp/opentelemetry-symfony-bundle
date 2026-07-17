<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

/**
 * Extracts semconv-shaped server.address / server.port values from parse_url() results.
 */
final class UrlParts
{
    /**
     * @param array<string, int|string> $parsed
     */
    public static function host(array $parsed): ?string
    {
        $host = $parsed['host'] ?? null;
        if (!\is_string($host) || '' === $host) {
            return null;
        }

        // parse_url() keeps IPv6 brackets; semconv server.address is the bare address.
        return trim($host, '[]');
    }

    /** Best-effort base_uri resolution for span attributes when the request URL is relative. */
    public static function join(string $base, string $ref): string
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref)) {
            return $ref;
        }

        $parsed = parse_url($base);
        if (!\is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return $ref;
        }

        if (str_starts_with($ref, '//')) {
            return $parsed['scheme'].':'.$ref;
        }

        $origin = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
        $path = \is_string($parsed['path'] ?? null) ? $parsed['path'] : '/';

        if ('' === $ref) {
            return $origin.$path;
        }

        // RFC 3986 §5.3: query-only and fragment-only references keep the base path.
        if (str_starts_with($ref, '?') || str_starts_with($ref, '#')) {
            return $origin.$path.$ref;
        }

        if (str_starts_with($ref, '/')) {
            return $origin.$ref;
        }

        $pos = strrpos($path, '/');
        $dir = false === $pos ? '/' : substr($path, 0, $pos + 1);

        return $origin.$dir.$ref;
    }

    /**
     * @param array<string, int|string> $parsed
     */
    public static function port(array $parsed): ?int
    {
        $port = $parsed['port'] ?? match (strtolower((string) ($parsed['scheme'] ?? ''))) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return null === $port ? null : (int) $port;
    }
}
