<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

final class TraceableStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly string $tracerName,
        private readonly bool $recordStatements,
        private readonly string $dbSystem,
        private readonly ?string $dbName,
        private readonly ?string $serverAddress,
        private readonly ?int $serverPort,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $tracer = Globals::tracerProvider()->getTracer($this->tracerName);
        $operation = SqlOperationExtractor::extract($this->sql);

        $spanName = $this->recordStatements
            ? SqlOperationExtractor::spanName($this->sql)
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
            $builder->setAttribute(DbAttributes::DB_QUERY_TEXT, $this->sql);
            $builder->setAttribute('db.statement', $this->sql);
        }

        if ($this->serverAddress !== null) {
            $builder->setAttribute(ServerAttributes::SERVER_ADDRESS, $this->serverAddress);
        }

        if ($this->serverPort !== null) {
            $builder->setAttribute(ServerAttributes::SERVER_PORT, $this->serverPort);
        }

        $span = $builder->startSpan();

        try {
            $result = parent::execute();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }

        return $result;
    }
}
