<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class TraceableStatementDbal4 extends AbstractStatementMiddleware
{
    use TraceableDbalTrait;

    public function __construct(
        Statement $statement,
        private readonly string $tracerName,
        private readonly bool $recordStatements,
        private readonly string $dbSystem,
        private readonly ?string $dbName,
        private readonly ?string $serverAddress,
        private readonly ?int $serverPort,
        private readonly string $sql,
        private readonly bool $onlyWithParent = false,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        return $this->traced($this->sql, fn (): Result => parent::execute());
    }
}
