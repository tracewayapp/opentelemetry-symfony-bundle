<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Traceway\OpenTelemetryBundle\Doctrine\Metrics\DbMetricRecorder;

final class MeteredStatementDbal3 extends AbstractStatementMiddleware
{
    use MeteredDbalTrait;

    public function __construct(
        Statement $statement,
        private readonly DbMetricRecorder $recorder,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    /**
     * @param mixed[]|null $params
     */
    public function execute($params = null): Result
    {
        return $this->metered($this->sql, fn (): Result => parent::execute($params));
    }
}
