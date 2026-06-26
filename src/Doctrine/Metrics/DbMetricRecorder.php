<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Doctrine\Metrics;

use Doctrine\DBAL\Driver\Exception as DriverException;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use Symfony\Contracts\Service\ResetInterface;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\SqlOperationExtractor;
use Traceway\OpenTelemetryBundle\Metrics\DurationBoundaries;
use Traceway\OpenTelemetryBundle\OpenTelemetryBundle;
use Traceway\OpenTelemetryBundle\Util\ErrorTypeResolver;

/**
 * Emits OpenTelemetry metrics for Doctrine DBAL operations.
 *
 * Bound to a single Doctrine connection at construction time so the per-connection
 * context (db.system.name, db.namespace, server.{address,port}) only resolves once.
 * Shared by {@see MeteredConnectionDbal3}, {@see MeteredConnectionDbal4} and their
 * statement counterparts; one recorder instance per connection.
 *
 * Metric: db.client.operation.duration (Histogram, seconds) — semconv Stable.
 */
final class DbMetricRecorder implements ResetInterface
{
    private ?MeterInterface $meter = null;
    private ?HistogramInterface $duration = null;

    public function __construct(
        private readonly string $meterName,
        private readonly string $dbSystem,
        private readonly ?string $dbName,
        private readonly ?string $serverAddress,
        private readonly ?int $serverPort,
    ) {
    }

    public function record(string $sql, int|float $start, ?\Throwable $exception): void
    {
        try {
            $attributes = $this->baseAttributes();

            $operation = SqlOperationExtractor::extract($sql);
            if ('UNKNOWN' !== $operation) {
                $attributes['db.operation.name'] = $operation;
            }

            $target = SqlOperationExtractor::extractTarget($sql);
            if (null !== $target) {
                $attributes['db.collection.name'] = $target;
            }

            $attributes['db.query.summary'] = SqlOperationExtractor::spanName($operation, $target, $this->dbName, $this->dbSystem);

            if (null !== $exception) {
                $attributes['error.type'] = ErrorTypeResolver::resolve($exception);

                $sqlState = $exception instanceof DriverException ? $exception->getSQLState() : null;
                if (null !== $sqlState && '' !== $sqlState) {
                    $attributes['db.response.status_code'] = $sqlState;
                }
            }

            $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
            $this->getDurationHistogram()->record($durationSeconds, $attributes);
        } catch (\Throwable) {
        }
    }

    public function reset(): void
    {
        $this->meter = null;
        $this->duration = null;
    }

    /**
     * @return array<non-empty-string, string|int>
     */
    private function baseAttributes(): array
    {
        $attributes = [
            'db.system.name' => $this->dbSystem,
        ];

        if (null !== $this->dbName) {
            $attributes['db.namespace'] = $this->dbName;
        }
        if (null !== $this->serverAddress) {
            $attributes['server.address'] = $this->serverAddress;
        }
        if (null !== $this->serverPort) {
            $attributes['server.port'] = $this->serverPort;
        }

        return $attributes;
    }

    private function getDurationHistogram(): HistogramInterface
    {
        return $this->duration ??= $this->getMeter()->createHistogram(
            'db.client.operation.duration',
            's',
            'Duration of database client operations',
            ['ExplicitBucketBoundaries' => DurationBoundaries::DB_SECONDS],
        );
    }

    private function getMeter(): MeterInterface
    {
        return $this->meter ??= Globals::meterProvider()->getMeter(
            $this->meterName,
            OpenTelemetryBundle::version(),
            OpenTelemetryBundle::SCHEMA_URL,
        );
    }
}
