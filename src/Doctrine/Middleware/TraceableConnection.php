<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

final class TraceableConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly string $tracerName,
        private readonly bool $recordStatements,
        private readonly string $dbSystem,
        private readonly ?string $dbName,
        private readonly ?string $serverAddress,
        private readonly ?int $serverPort,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TraceableStatement(
            parent::prepare($sql),
            $this->tracerName,
            $this->recordStatements,
            $this->dbSystem,
            $this->dbName,
            $this->serverAddress,
            $this->serverPort,
            $sql,
        );
    }

    public function query(string $sql): Result
    {
        $span = $this->startSpan($sql);

        try {
            $result = parent::query($sql);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }

        return $result;
    }

    public function exec(string $sql): int
    {
        $span = $this->startSpan($sql);

        try {
            $result = parent::exec($sql);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }

        return (int) $result;
    }

    public function beginTransaction(): void
    {
        $span = $this->startSpan('BEGIN');

        try {
            parent::beginTransaction();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    public function commit(): void
    {
        $span = $this->startSpan('COMMIT');

        try {
            parent::commit();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    public function rollBack(): void
    {
        $span = $this->startSpan('ROLLBACK');

        try {
            parent::rollBack();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    private function startSpan(string $sql): SpanInterface
    {
        $tracer = Globals::tracerProvider()->getTracer($this->tracerName);
        $operation = SqlOperationExtractor::extract($sql);

        $spanName = $this->recordStatements
            ? SqlOperationExtractor::spanName($sql)
            : SqlOperationExtractor::operationSpanName($operation, $this->dbName);

        $builder = $tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(DbAttributes::DB_SYSTEM_NAME, $this->dbSystem)
            ->setAttribute('db.system', $this->dbSystem)
            ->setAttribute(DbAttributes::DB_OPERATION_NAME, $operation)
            ->setAttribute('db.operation', $operation);

        if ($this->dbName !== null) {
            $builder->setAttribute(DbAttributes::DB_NAMESPACE, $this->dbName);
            $builder->setAttribute('db.name', $this->dbName);
        }

        if ($this->recordStatements) {
            $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, $sql);
            $builder->setAttribute('db.statement', $sql);
        }

        if ($this->serverAddress !== null) {
            $builder->setAttribute(ServerAttributes::SERVER_ADDRESS, $this->serverAddress);
        }

        if ($this->serverPort !== null) {
            $builder->setAttribute(ServerAttributes::SERVER_PORT, $this->serverPort);
        }

        return $builder->startSpan();
    }
}
