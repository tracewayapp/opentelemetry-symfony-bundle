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
