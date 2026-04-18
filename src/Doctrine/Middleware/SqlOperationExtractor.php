<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

/**
 * @internal
 */
final class SqlOperationExtractor
{
    private const IDENT = '(?:[A-Za-z_][A-Za-z0-9_$.]*|`[^`]+`|"[^"]+"|\[[^\]]+\])';

    /**
     * @return non-empty-string
     */
    public static function extract(string $sql): string
    {
        $sql = ltrim($sql);
        if ($sql === '' || !preg_match('/^(\S+)/', $sql, $matches)) {
            return 'UNKNOWN';
        }

        return strtoupper($matches[1]);
    }

    /**
     * Extracts the primary table/collection name from a SQL statement.
     *
     * Returns null when the target can't be confidently determined (DDL,
     * transaction control, multi-table updates, subquery-heavy SELECTs).
     * The fallback is a low-cardinality operation-only span name, which is
     * still spec-compliant per OTel database semconv.
     */
    public static function extractTarget(string $sql): ?string
    {
        $sql = ltrim($sql);
        if ($sql === '') {
            return null;
        }

        $patterns = [
            '/^INSERT\s+(?:OR\s+\w+\s+)?INTO\s+(' . self::IDENT . ')/i',
            '/^UPDATE\s+(?:OR\s+\w+\s+)?(' . self::IDENT . ')\s+SET\b/i',
            '/^DELETE\s+FROM\s+(' . self::IDENT . ')/i',
            '/^SELECT\b.*?\bFROM\s+(' . self::IDENT . ')/is',
            '/^REPLACE\s+INTO\s+(' . self::IDENT . ')/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $m)) {
                return self::stripQuotes($m[1]);
            }
        }

        return null;
    }

    /**
     * Builds a low-cardinality span name following OTel database semconv:
     * `{operation} {target}` when the table is known, otherwise
     * `{operation} {db.namespace}` as a fallback, otherwise just `{operation}`.
     *
     * @param non-empty-string $operation
     *
     * @return non-empty-string
     */
    public static function spanName(string $operation, ?string $target, ?string $dbName): string
    {
        $suffix = $target ?? $dbName;

        return $suffix !== null ? $operation . ' ' . $suffix : $operation;
    }

    private static function stripQuotes(string $ident): string
    {
        if ($ident === '') {
            return $ident;
        }

        $first = $ident[0];
        if ($first === '`' || $first === '"' || $first === '[') {
            return substr($ident, 1, -1);
        }

        return $ident;
    }
}
