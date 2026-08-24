<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class TraceableConnectionDbal3 extends AbstractConnectionMiddleware
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
        private readonly bool $onlyWithParent = false,
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
            $this->onlyWithParent,
        );
    }

    public function query(string $sql): Result
    {
        return $this->traced($sql, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        return (int) $this->traced($sql, fn () => parent::exec($sql));
    }

    public function beginTransaction(): bool
    {
        return (bool) $this->traced('BEGIN', fn () => parent::beginTransaction());
    }

    public function commit(): bool
    {
        return (bool) $this->traced('COMMIT', fn () => parent::commit());
    }

    public function rollBack(): bool
    {
        return (bool) $this->traced('ROLLBACK', fn () => parent::rollBack());
    }
}
