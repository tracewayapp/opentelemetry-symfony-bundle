<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

/**
 * @internal Shared span-building logic for Doctrine connection and statement tracing.
 */
final class DbSpanBuilder
{
    public static function create(
        TracerInterface $tracer,
        string $sql,
        bool $recordStatements,
        string $dbSystem,
        ?string $dbName,
        ?string $serverAddress,
        ?int $serverPort,
    ): SpanBuilderInterface {
        $operation = SqlOperationExtractor::extract($sql);
        $target = SqlOperationExtractor::extractTarget($sql);
        $spanName = SqlOperationExtractor::spanName($operation, $target, $dbName);

        $builder = $tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(DbAttributes::DB_SYSTEM_NAME, $dbSystem)
            ->setAttribute('db.system', $dbSystem)
            ->setAttribute(DbAttributes::DB_OPERATION_NAME, $operation)
            ->setAttribute('db.operation', $operation)
            ->setAttribute(DbAttributes::DB_QUERY_SUMMARY, $spanName);

        if ($target !== null) {
            $builder->setAttribute(DbAttributes::DB_COLLECTION_NAME, $target);
        }

        if ($dbName !== null) {
            $builder->setAttribute(DbAttributes::DB_NAMESPACE, $dbName);
            $builder->setAttribute('db.name', $dbName);
        }

        if ($recordStatements) {
            $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, $sql);
            $builder->setAttribute('db.statement', $sql);
        }

        if ($serverAddress !== null) {
            $builder->setAttribute(ServerAttributes::SERVER_ADDRESS, $serverAddress);
        }

        if ($serverPort !== null) {
            $builder->setAttribute(ServerAttributes::SERVER_PORT, $serverPort);
        }

        return $builder;
    }
}
