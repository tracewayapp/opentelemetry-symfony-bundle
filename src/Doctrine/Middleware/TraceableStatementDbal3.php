<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;

final class TraceableStatementDbal3 extends AbstractStatementMiddleware
{
    private ?TracerInterface $tracer = null;
    private ?bool $enabled = null;

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

    /**
     * @param mixed[]|null $params
     */
    public function execute($params = null): Result
    {
        if (!($this->enabled ??= $this->getTracer()->isEnabled())) {
            return parent::execute($params);
        }

        $span = DbSpanBuilder::startSpan(
            $this->getTracer(),
            $this->sql,
            $this->recordStatements,
            $this->dbSystem,
            $this->dbName,
            $this->serverAddress,
            $this->serverPort,
        );

        try {
            $result = parent::execute($params);
        } catch (\Throwable $e) {
            DbSpanBuilder::recordFailure($span, $e);

            throw $e;
        } finally {
            $span->end();
        }

        return $result;
    }

    private function getTracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer(
            $this->tracerName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }
}
