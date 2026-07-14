<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Middleware;

/**
 * Metric-wrapped execution shared by the metered DBAL connection/statement
 * middlewares (DBAL 3 and DBAL 4 variants).
 */
trait MeteredDbalTrait
{
    /**
     * @template T
     *
     * @param \Closure(): T $op
     *
     * @return T
     */
    private function metered(string $sql, \Closure $op): mixed
    {
        $start = hrtime(true);
        $exception = null;

        try {
            return $op();
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            $this->recorder->record($sql, $start, $exception);
        }
    }
}
