<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Util;

/**
 * Redacts credentials and sensitive query parameters from URLs per OTel semconv.
 */
final class UrlSanitizer
{
    private const REDACTED = 'REDACTED';

    /** Query parameter names the semconv requires redacted by default. */
    private const SENSITIVE_QUERY_PARAMS = ['X-Amz-Signature', 'X-Amz-Credential', 'X-Amz-Security-Token', 'sig', 'X-Goog-Signature'];

    public static function sanitizeUrl(string $url): string
    {
        $sanitized = preg_replace(
            '#^([a-z][a-z0-9+.-]*://)[^/?\#@]+@#i',
            '$1'.self::REDACTED.':'.self::REDACTED.'@',
            $url,
        ) ?? $url;

        $queryPos = strpos($sanitized, '?');
        if (false === $queryPos) {
            return $sanitized;
        }

        $fragmentPos = strpos($sanitized, '#', $queryPos);
        $query = false === $fragmentPos
            ? substr($sanitized, $queryPos + 1)
            : substr($sanitized, $queryPos + 1, $fragmentPos - $queryPos - 1);

        $sanitizedQuery = self::sanitizeQuery($query);
        if ($sanitizedQuery === $query) {
            return $sanitized;
        }

        return substr($sanitized, 0, $queryPos + 1)
            .$sanitizedQuery
            .(false === $fragmentPos ? '' : substr($sanitized, $fragmentPos));
    }

    public static function sanitizeQuery(string $query): string
    {
        if ('' === $query) {
            return $query;
        }

        $pairs = explode('&', $query);
        $changed = false;

        foreach ($pairs as $i => $pair) {
            $eq = strpos($pair, '=');
            $key = false === $eq ? $pair : substr($pair, 0, $eq);

            if (\in_array(rawurldecode($key), self::SENSITIVE_QUERY_PARAMS, true)) {
                $pairs[$i] = $key.'='.self::REDACTED;
                $changed = true;
            }
        }

        return $changed ? implode('&', $pairs) : $query;
    }
}
