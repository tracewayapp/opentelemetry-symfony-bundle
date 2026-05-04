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
        $start = hrtime(true);
        $exception = null;

        try {
            return parent::query($sql);
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            $this->recorder->record($sql, $start, $exception);
        }
    }

    public function exec(string $sql): int
    {
        $start = hrtime(true);
        $exception = null;

        try {
            return (int) parent::exec($sql);
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            $this->recorder->record($sql, $start, $exception);
        }
    }

    public function beginTransaction(): bool
    {
        $start = hrtime(true);
        $exception = null;

        try {
            return (bool) parent::beginTransaction();
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            $this->recorder->record('BEGIN', $start, $exception);
        }
    }

    public function commit(): bool
    {
        $start = hrtime(true);
        $exception = null;

        try {
            return (bool) parent::commit();
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            $this->recorder->record('COMMIT', $start, $exception);
        }
    }

    public function rollBack(): bool
    {
        $start = hrtime(true);
        $exception = null;

        try {
            return (bool) parent::rollBack();
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            $this->recorder->record('ROLLBACK', $start, $exception);
        }
    }
}
