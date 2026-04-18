<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

final class TraceableConnectionDbal3 extends AbstractConnectionMiddleware
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

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
        return new TraceableStatementDbal3(
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
        if (!$this->isEnabled()) {
            return parent::query($sql);
        }

        $span = $this->startSpan($sql);

        try {
            $result = parent::query($sql);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);

            throw $e;
        } finally {
            $span->end();
        }

        return $result;
    }

    public function exec(string $sql): int
    {
        if (!$this->isEnabled()) {
            return (int) parent::exec($sql);
        }

        $span = $this->startSpan($sql);

        try {
            $result = parent::exec($sql);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);

            throw $e;
        } finally {
            $span->end();
        }

        return (int) $result;
    }

    public function beginTransaction(): bool
    {
        if (!$this->isEnabled()) {
            parent::beginTransaction();

            return true;
        }

        $span = $this->startSpan('BEGIN');

        try {
            parent::beginTransaction();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);

            throw $e;
        } finally {
            $span->end();
        }

        return true;
    }

    public function commit(): bool
    {
        if (!$this->isEnabled()) {
            parent::commit();

            return true;
        }

        $span = $this->startSpan('COMMIT');

        try {
            parent::commit();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);

            throw $e;
        } finally {
            $span->end();
        }

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->isEnabled()) {
            parent::rollBack();

            return true;
        }

        $span = $this->startSpan('ROLLBACK');

        try {
            parent::rollBack();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $e::class);

            throw $e;
        } finally {
            $span->end();
        }

        return true;
    }

    private function isEnabled(): bool
    {
        return $this->enabled ??= $this->getTracer()->isEnabled();
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer($this->tracerName);
    }

    private function startSpan(string $sql): SpanInterface
    {
        $tracer = $this->getTracer();

        return DbSpanBuilder::create(
            $tracer,
            $sql,
            $this->recordStatements,
            $this->dbSystem,
            $this->dbName,
            $this->serverAddress,
            $this->serverPort,
        )->startSpan();
    }
}
