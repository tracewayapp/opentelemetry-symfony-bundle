<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Exception as DriverException;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

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
        $spanName = SqlOperationExtractor::spanName($operation, $target, $dbName, $dbSystem);

        $builder = $tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(DbAttributes::DB_SYSTEM_NAME, $dbSystem)
            ->setAttribute('db.system', DbSystemResolver::legacyValue($dbSystem))
            ->setAttribute(DbAttributes::DB_QUERY_SUMMARY, $spanName);

        if ('UNKNOWN' !== $operation) {
            $builder->setAttribute(DbAttributes::DB_OPERATION_NAME, $operation);
            $builder->setAttribute('db.operation', $operation);
        }

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

    public static function startSpan(
        TracerInterface $tracer,
        string $sql,
        bool $recordStatements,
        string $dbSystem,
        ?string $dbName,
        ?string $serverAddress,
        ?int $serverPort,
    ): SpanInterface {
        try {
            return self::create($tracer, $sql, $recordStatements, $dbSystem, $dbName, $serverAddress, $serverPort)->startSpan();
        } catch (\Throwable) {
            return Span::getInvalid();
        }
    }

    /**
     * Records a failed operation: exception event, error.type, SQLSTATE, error status.
     */
    public static function recordFailure(SpanInterface $span, \Throwable $e): void
    {
        $span->recordException($e);
        $span->setAttribute(ErrorAttributes::ERROR_TYPE, ErrorTypeResolver::resolve($e));

        $sqlState = $e instanceof DriverException ? $e->getSQLState() : null;
        if (null !== $sqlState && '' !== $sqlState) {
            $span->setAttribute(DbAttributes::DB_RESPONSE_STATUS_CODE, $sqlState);
        }

        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    }
}
