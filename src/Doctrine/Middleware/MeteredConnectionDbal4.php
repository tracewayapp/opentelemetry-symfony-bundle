<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;

final class MeteredConnectionDbal4 extends AbstractConnectionMiddleware
{
    use MeteredDbalTrait;

    public function __construct(
        Connection $connection,
        private readonly DbMetricRecorder $recorder,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new MeteredStatementDbal4(parent::prepare($sql), $this->recorder, $sql);
    }

    public function query(string $sql): Result
    {
        return $this->metered($sql, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        return (int) $this->metered($sql, fn (): int|string => parent::exec($sql));
    }

    public function beginTransaction(): void
    {
        $this->metered('BEGIN', function (): void { parent::beginTransaction(); });
    }

    public function commit(): void
    {
        $this->metered('COMMIT', function (): void { parent::commit(); });
    }

    public function rollBack(): void
    {
        $this->metered('ROLLBACK', function (): void { parent::rollBack(); });
    }
}
