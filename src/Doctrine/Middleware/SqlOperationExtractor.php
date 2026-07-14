<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

/**
 * @internal
 */
final class SqlOperationExtractor
{
    private const IDENT = '(?:[A-Za-z_][A-Za-z0-9_$.]*|`[^`]+`|"[^"]+"|\[[^\]]+\])';

    private const CTE_BODY_OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE'];

    /**
     * @return non-empty-string
     */
    public static function extract(string $sql): string
    {
        $sql = self::stripLeadingNoise($sql);
        if ('' === $sql || !preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches)) {
            return 'UNKNOWN';
        }

        $operation = strtoupper($matches[1]);

        if ('WITH' === $operation) {
            return self::extractCteBodyOperation($sql) ?? $operation;
        }

        return $operation;
    }

    /**
     * Strips leading whitespace, SQL comments, and wrapping parentheses
     * so the real operation keyword is first (e.g. "/* hint *\/ SELECT").
     */
    private static function stripLeadingNoise(string $sql): string
    {
        $previous = null;
        while ($previous !== $sql) {
            $previous = $sql;
            $sql = ltrim($sql, " \t\n\r\0\x0B(");

            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/', 2);
                $sql = false === $end ? '' : substr($sql, $end + 2);
            } elseif (str_starts_with($sql, '--') || str_starts_with($sql, '#')) {
                $end = strpos($sql, "\n");
                $sql = false === $end ? '' : substr($sql, $end + 1);
            }
        }

        return $sql;
    }

    /**
     * For "WITH x AS (...) SELECT ..." finds the statement verb after the CTE
     * list: the first known operation keyword at parenthesis depth zero.
     *
     * @return non-empty-string|null
     */
    private static function extractCteBodyOperation(string $sql): ?string
    {
        return self::scanDepthZeroKeyword($sql, self::CTE_BODY_OPERATIONS, 4)['word'] ?? null;
    }

    /**
     * Scans for the first keyword at parenthesis depth zero, skipping string
     * literals, quoted identifiers, and SQL comments.
     *
     * @param list<non-empty-string> $keywords Uppercase keywords to match
     *
     * @return array{word: non-empty-string, end: int}|null
     */
    private static function scanDepthZeroKeyword(string $sql, array $keywords, int $offset): ?array
    {
        $depth = 0;
        $length = \strlen($sql);
        $i = $offset;

        while ($i < $length) {
            $char = $sql[$i];

            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                $depth = max(0, $depth - 1);
            } elseif ("'" === $char || '"' === $char || '`' === $char) {
                $i = self::skipQuoted($sql, $i, $char);
                continue;
            } elseif ('/' === $char && '*' === ($sql[$i + 1] ?? '')) {
                $end = strpos($sql, '*/', $i + 2);
                if (false === $end) {
                    return null;
                }
                $i = $end + 2;
                continue;
            } elseif (('-' === $char && '-' === ($sql[$i + 1] ?? '')) || '#' === $char) {
                $end = strpos($sql, "\n", $i);
                if (false === $end) {
                    return null;
                }
                $i = $end + 1;
                continue;
            } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $m, 0, $i)) {
                $word = strtoupper($m[0]);
                if (0 === $depth && \in_array($word, $keywords, true)) {
                    return ['word' => $word, 'end' => $i + \strlen($m[0])];
                }
                $i += \strlen($m[0]);
                continue;
            }

            ++$i;
        }

        return null;
    }

    private static function skipQuoted(string $sql, int $start, string $quote): int
    {
        $i = $start + 1;
        $length = \strlen($sql);

        while ($i < $length) {
            if ($sql[$i] === $quote) {
                // Doubled quote is an escaped quote in every SQL dialect.
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }
            if ('\\' === $sql[$i]) {
                ++$i;
            }
            ++$i;
        }

        return $length;
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
        $sql = self::stripLeadingNoise($sql);
        if ('' === $sql) {
            return null;
        }

        if (1 === preg_match('/^SELECT\b/i', $sql)) {
            return self::extractSelectTarget($sql);
        }

        $patterns = [
            '/^INSERT\s+(?:OR\s+\w+\s+)?INTO\s+('.self::IDENT.')/i',
            '/^UPDATE\s+(?:OR\s+\w+\s+)?('.self::IDENT.')\s+SET\b/i',
            '/^DELETE\s+FROM\s+('.self::IDENT.')/i',
            '/^REPLACE\s+INTO\s+('.self::IDENT.')/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $m)) {
                return self::stripQuotes($m[1]);
            }
        }

        return null;
    }

    /**
     * Finds the SELECT's depth-zero FROM so string literals and scalar
     * subqueries in the select list can't hijack the collection name.
     */
    private static function extractSelectTarget(string $sql): ?string
    {
        $found = self::scanDepthZeroKeyword($sql, ['FROM'], 6);
        if (null === $found) {
            return null;
        }

        $rest = substr($sql, $found['end']);
        if (1 === preg_match('/\A\s*('.self::IDENT.')/', $rest, $m)) {
            return self::stripQuotes($m[1]);
        }

        // Derived table "FROM (SELECT ... FROM inner)" — report the inner table.
        if (1 === preg_match('/\bFROM\s+('.self::IDENT.')/is', $rest, $m)) {
            return self::stripQuotes($m[1]);
        }

        return null;
    }

    /**
     * Builds a low-cardinality span name following OTel database semconv:
     * `{operation} {target}` when the table is known, otherwise
     * `{operation} {db.namespace}`, otherwise just `{operation}`. When the
     * operation is unknown the spec fallback chain is target, namespace,
     * then db.system.name — never an invented token.
     *
     * @param non-empty-string $operation
     *
     * @return non-empty-string
     */
    public static function spanName(string $operation, ?string $target, ?string $dbName, ?string $dbSystem = null): string
    {
        if ('UNKNOWN' === $operation) {
            $fallback = $target ?? $dbName ?? $dbSystem;

            return (null !== $fallback && '' !== $fallback) ? $fallback : 'db';
        }

        $suffix = $target ?? $dbName;

        return null !== $suffix ? $operation.' '.$suffix : $operation;
    }

    private static function stripQuotes(string $ident): string
    {
        if ('' === $ident) {
            return $ident;
        }

        $first = $ident[0];
        if ('`' === $first || '"' === $first || '[' === $first) {
            return substr($ident, 1, -1);
        }

        return $ident;
    }
}
