<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;

final class MeteredConnectionDbal3 extends AbstractConnectionMiddleware
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
        return new MeteredStatementDbal3(parent::prepare($sql), $this->recorder, $sql);
    }

    public function query(string $sql): Result
    {
        return $this->metered($sql, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int
    {
        return (int) $this->metered($sql, fn () => parent::exec($sql));
    }

    public function beginTransaction(): bool
    {
        return (bool) $this->metered('BEGIN', fn () => parent::beginTransaction());
    }

    public function commit(): bool
    {
        return (bool) $this->metered('COMMIT', fn () => parent::commit());
    }

    public function rollBack(): bool
    {
        return (bool) $this->metered('ROLLBACK', fn () => parent::rollBack());
    }
}
