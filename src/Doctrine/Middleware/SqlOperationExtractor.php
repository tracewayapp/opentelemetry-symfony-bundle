<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

/**
 * @internal
 */
final class SqlOperationExtractor
{
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
     * Truncates SQL to a reasonable span-name length.
     *
     * @return non-empty-string
     */
    public static function spanName(string $sql): string
    {
        $sql = ltrim($sql);
        if ($sql === '') {
            return 'SQL';
        }

        if (mb_strlen($sql, 'UTF-8') > 120) {
            return mb_substr($sql, 0, 117, 'UTF-8') . '...';
        }

        return $sql;
    }

    /**
     * Builds a concise span name from the operation and optional db name.
     *
     * @param non-empty-string $operation
     *
     * @return non-empty-string
     */
    public static function operationSpanName(string $operation, ?string $dbName): string
    {
        return $dbName !== null ? $operation . ' ' . $dbName : $operation;
    }
}
