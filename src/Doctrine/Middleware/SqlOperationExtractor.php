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
