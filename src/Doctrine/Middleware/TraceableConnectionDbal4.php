<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class TraceableConnectionDbal4 extends AbstractConnectionMiddleware
{
    use TraceableDbalTrait;

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
        return new TraceableStatementDbal4(
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
        return $this->traced($sql, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        return (int) $this->traced($sql, fn (): int|string => parent::exec($sql));
    }

    public function beginTransaction(): void
    {
        $this->traced('BEGIN', function (): void { parent::beginTransaction(); });
    }

    public function commit(): void
    {
        $this->traced('COMMIT', function (): void { parent::commit(); });
    }

    public function rollBack(): void
    {
        $this->traced('ROLLBACK', function (): void { parent::rollBack(); });
    }
}
